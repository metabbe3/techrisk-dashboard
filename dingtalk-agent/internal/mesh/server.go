package mesh

import (
	"context"
	"crypto/subtle"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"strings"
	"sync"
	"time"
)

// Server coordinates the GoMesh cluster as a leader.
type Server struct {
	listenAddr string
	token      string
	queue      *Queue
	server     *http.Server
	listener   net.Listener
	mu         sync.Mutex
	stopChan   chan struct{}
	startTime  time.Time
}

// NewServer initializes a GoMesh HTTP/1.1 leader server.
func NewServer(listenAddr, token string, queue *Queue) *Server {
	return &Server{
		listenAddr: listenAddr,
		token:      token,
		queue:      queue,
		stopChan:   make(chan struct{}),
		startTime:  time.Now(),
	}
}

// Queue returns the server's task queue.
func (s *Server) Queue() *Queue {
	return s.queue
}

// Start begins listening on the configured address and runs the liveness sweeper.
func (s *Server) Start() error {
	ln, err := net.Listen("tcp", s.listenAddr)
	if err != nil {
		return fmt.Errorf("failed to listen on %s: %w", s.listenAddr, err)
	}
	s.listener = ln

	mux := http.NewServeMux()
	mux.HandleFunc("/rpc", s.handleRPC)
	mux.HandleFunc("/nexttask", s.handleNextTask)
	mux.HandleFunc("/statusz", s.handleStatusz)

	handler := s.authMiddleware(mux)
	s.server = &http.Server{
		Handler: handler,
	}

	// Start liveness sweeper: runs every 15s to check 90s heartbeat timeouts & mission timeouts
	go s.sweeperLoop()

	log.Printf("[mesh] leader listening on %s (proto: %s)", ln.Addr().String(), MeshProtocol)
	go func() {
		if err := s.server.Serve(ln); err != nil && err != http.ErrServerClosed {
			log.Printf("[mesh] leader server error: %v", err)
		}
	}()

	return nil
}

// Addr returns the bound address of the server listener (useful in tests).
func (s *Server) Addr() string {
	if s.listener != nil {
		return s.listener.Addr().String()
	}
	return s.listenAddr
}

// Stop gracefully shuts down the leader server and liveness sweeper.
func (s *Server) Stop(ctx context.Context) error {
	close(s.stopChan)
	if s.server != nil {
		stopCtx := ctx
		if _, ok := ctx.Deadline(); !ok {
			var cancel context.CancelFunc
			stopCtx, cancel = context.WithTimeout(ctx, 2*time.Second)
			defer cancel()
		}
		return s.server.Shutdown(stopCtx)
	}
	return nil
}

func (s *Server) authMiddleware(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		token := r.Header.Get(HeaderMeshToken)
		if subtle.ConstantTimeCompare([]byte(token), []byte(s.token)) != 1 {
			log.Printf("[mesh] unauthorized request to %s from %s", r.URL.Path, r.RemoteAddr)
			http.Error(w, `{"error":"unauthorized"}`, http.StatusUnauthorized)
			return
		}
		next.ServeHTTP(w, r)
	})
}

func (s *Server) handleRPC(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}

	body, err := io.ReadAll(io.LimitReader(r.Body, 1<<20))
	if err != nil {
		http.Error(w, "failed to read body", http.StatusBadRequest)
		return
	}

	var req RPCRequest
	if err := json.Unmarshal(body, &req); err != nil {
		writeRPCError(w, nil, -32700, "parse error")
		return
	}

	w.Header().Set("Content-Type", "application/json")
	w.Header().Set(HeaderMeshProto, ProtoVersion)

	switch req.Method {
	case "mesh.register":
		var p RegisterReq
		if err := json.Unmarshal(req.Params, &p); err != nil || p.Worker.ID == "" {
			writeRPCError(w, req.ID, -32602, "invalid params for mesh.register")
			return
		}
		wInfo := s.queue.RegisterWorker(p.Worker.ID, p.Worker.Capabilities)
		resp := RegisterResp{
			WorkerID:     wInfo.ID,
			HeartbeatSec: HeartbeatSec,
			Poll:         "/nexttask",
		}
		// Leader-restart claim check: report which of the worker's claimed
		// tasks this (possibly restarted) leader no longer tracks as its
		// in-flight work — the replay already requeued them elsewhere.
		if len(p.KnownTasks) > 0 {
			resp.LostTasks = s.queue.LostTasks(p.Worker.ID, p.KnownTasks)
			if len(resp.LostTasks) > 0 {
				log.Printf("[mesh] register: worker %s claimed %d task(s) this leader no longer tracks", p.Worker.ID, len(resp.LostTasks))
			}
		}
		writeRPCSuccess(w, req.ID, resp)

	case "mesh.heartbeat":
		var p HeartbeatReq
		if err := json.Unmarshal(req.Params, &p); err != nil || p.WorkerID == "" {
			writeRPCError(w, req.ID, -32602, "invalid params for mesh.heartbeat")
			return
		}
		pending, err := s.queue.Heartbeat(p.WorkerID)
		if err != nil {
			writeRPCError(w, req.ID, -32000, err.Error())
			return
		}
		abortTasks := s.queue.TasksToAbort(p.WorkerID)
		resp := HeartbeatResp{
			OK:           true,
			TasksPending: pending,
			AbortTasks:   abortTasks,
		}
		writeRPCSuccess(w, req.ID, resp)

	case "mesh.get_mission_result":
		var p struct {
			MissionID string `json:"mission_id"`
		}
		if err := json.Unmarshal(req.Params, &p); err != nil || p.MissionID == "" {
			writeRPCError(w, req.ID, -32602, "invalid params for mesh.get_mission_result")
			return
		}
		st, err := s.queue.MissionStatus(p.MissionID)
		if err != nil {
			writeRPCError(w, req.ID, -32000, err.Error())
			return
		}
		writeRPCSuccess(w, req.ID, map[string]any{"mission_id": st.ID, "status": st.Status, "final_result": st.FinalResult})

	case "mesh.add_mission":
		var p struct {
			Mission  string         `json:"mission"`
			Subtasks []SubtaskInput `json:"subtasks"`
		}
		if err := json.Unmarshal(req.Params, &p); err != nil {
			writeRPCError(w, req.ID, -32602, "invalid params for mesh.add_mission")
			return
		}
		p.Mission = strings.TrimSpace(p.Mission)
		if p.Mission == "" || len(p.Subtasks) < 1 || len(p.Subtasks) > 50 {
			writeRPCError(w, req.ID, -32602, "invalid params: mission must be non-empty and specify 1..50 subtasks")
			return
		}
		for _, st := range p.Subtasks {
			if strings.TrimSpace(st.Goal) == "" {
				writeRPCError(w, req.ID, -32602, "invalid params: subtask goal cannot be empty")
				return
			}
		}
		m, err := s.queue.AddMission(p.Mission, p.Subtasks)
		if err != nil {
			code := -32000
			if strings.Contains(err.Error(), "mesh queue full") {
				code = -32005
			}
			writeRPCError(w, req.ID, code, err.Error())
			return
		}
		writeRPCSuccess(w, req.ID, m)

	case "mesh.report":
		var p ReportReq
		if err := json.Unmarshal(req.Params, &p); err != nil || p.WorkerID == "" || p.TaskID == "" {
			writeRPCError(w, req.ID, -32602, "invalid params for mesh.report")
			return
		}
		if err := s.queue.ReportTask(p); err != nil {
			writeRPCError(w, req.ID, -32000, err.Error())
			return
		}
		writeRPCSuccess(w, req.ID, ReportResp{OK: true})

	case "mesh.list_missions":
		var p struct {
			Status string `json:"status"`
			Limit  int    `json:"limit"`
		}
		if len(req.Params) > 0 && string(req.Params) != "null" {
			if err := json.Unmarshal(req.Params, &p); err != nil {
				writeRPCError(w, req.ID, -32602, "invalid params for mesh.list_missions")
				return
			}
		}
		missions := s.queue.ListMissions(p.Status, p.Limit)
		writeRPCSuccess(w, req.ID, missions)

	case "mesh.list_tasks":
		var p struct {
			MissionID string `json:"mission_id"`
		}
		if err := json.Unmarshal(req.Params, &p); err != nil || strings.TrimSpace(p.MissionID) == "" {
			writeRPCError(w, req.ID, -32602, "invalid params for mesh.list_tasks")
			return
		}
		tasks, err := s.queue.ListTasks(p.MissionID)
		if err != nil {
			writeRPCError(w, req.ID, -32000, err.Error())
			return
		}
		writeRPCSuccess(w, req.ID, tasks)

	default:
		writeRPCError(w, req.ID, -32601, fmt.Sprintf("method not found: %s", req.Method))
	}
}

func (s *Server) handleNextTask(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}

	workerID := r.URL.Query().Get("id")
	if workerID == "" {
		http.Error(w, "missing id parameter", http.StatusBadRequest)
		return
	}

	w.Header().Set(HeaderMeshProto, ProtoVersion)

	// Long poll up to 50 seconds (respecting request context for immediate unblocking on client close or shutdown)
	task, err := s.queue.GetNextTaskContext(r.Context(), workerID, DefaultPollSec*time.Second)
	if err != nil || task == nil {
		w.WriteHeader(http.StatusNoContent)
		return
	}

	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusOK)
	if err := json.NewEncoder(w).Encode(task); err != nil {
		// Client disconnected mid-dispatch: put the task back so another worker can take it.
		log.Printf("[mesh] dispatch write failed (worker gone?): %v — requeueing task %s", err, task.ID)
		s.queue.RequeueOnDispatchFailure(task.ID)
	}
}

func (s *Server) handleStatusz(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}

	workers, pending, inFlight, activeMissions := s.queue.ClusterStats()

	alive := 0
	dead := 0
	for _, w := range workers {
		if w.Status == "dead" {
			dead++
		} else {
			alive++
		}
	}

	uptimeSec := int64(time.Since(s.startTime).Seconds())

	resp := map[string]any{
		"protocol":   MeshProtocol,
		"role":       "leader",
		"uptime_sec": uptimeSec,
		"workers": map[string]int{
			"total": len(workers),
			"alive": alive,
			"dead":  dead,
		},
		"queue": map[string]int{
			"pending":         pending,
			"in_flight":       inFlight,
			"active_missions": activeMissions,
		},
		"workers_detail": workers,
	}

	w.Header().Set("Content-Type", "application/json")
	w.Header().Set(HeaderMeshProto, ProtoVersion)
	w.WriteHeader(http.StatusOK)
	_ = json.NewEncoder(w).Encode(resp)
}

func (s *Server) sweeperLoop() {
	ticker := time.NewTicker(15 * time.Second)
	defer ticker.Stop()

	for {
		select {
		case <-s.stopChan:
			return
		case <-ticker.C:
			s.queue.SweepTaskTimeouts()
			s.queue.SweepDeadWorkers(DeadTimeoutSec * time.Second)
			s.queue.SweepMissionTimeouts()
			_ = s.queue.MaybeCompact()
		}
	}
}

func writeRPCSuccess(w http.ResponseWriter, id any, result any) {
	resp := RPCResponse{
		JSONRPC: "2.0",
		ID:      id,
		Result:  result,
	}
	_ = json.NewEncoder(w).Encode(resp)
}

func writeRPCError(w http.ResponseWriter, id any, code int, message string) {
	resp := RPCResponse{
		JSONRPC: "2.0",
		ID:      id,
		Error: &RPCError{
			Code:    code,
			Message: message,
		},
	}
	_ = json.NewEncoder(w).Encode(resp)
}
