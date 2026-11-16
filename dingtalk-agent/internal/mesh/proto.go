package mesh

import (
	"encoding/json"
	"time"
)

const (
	HeaderMeshToken       = "X-Mesh-Token"
	HeaderMeshProto       = "X-Mesh-Proto"
	ProtoVersion          = "1"
	MeshMajor             = 1
	MeshMinor             = 1
	MeshProtocol          = "GoMesh/1.1"
	DefaultPollSec        = 50
	HeartbeatSec          = 30
	DeadTimeoutSec        = 90
	DefaultTaskTimeoutSec = 300
)

// RPCRequest represents a JSON-RPC 2.0 request.
type RPCRequest struct {
	JSONRPC string          `json:"jsonrpc"`
	ID      any             `json:"id"`
	Method  string          `json:"method"`
	Params  json.RawMessage `json:"params,omitempty"`
}

// RPCResponse represents a JSON-RPC 2.0 response.
type RPCResponse struct {
	JSONRPC string    `json:"jsonrpc"`
	ID      any       `json:"id"`
	Result  any       `json:"result,omitempty"`
	Error   *RPCError `json:"error,omitempty"`
}

// RPCError represents a JSON-RPC 2.0 error.
type RPCError struct {
	Code    int    `json:"code"`
	Message string `json:"message"`
}

// RegisterReq parameters for mesh.register.
type RegisterReq struct {
	Worker struct {
		ID           string   `json:"id"`
		Capabilities []string `json:"capabilities"`
	} `json:"worker"`
	// KnownTasks lets a RE-registering worker ask which of its in-flight
	// tasks the leader still tracks. Anything not tracked comes back in
	// RegisterResp.LostTasks (leader-restart duplicate-work guard).
	KnownTasks []string `json:"known_tasks,omitempty"`
}

// RegisterResp result for mesh.register.
type RegisterResp struct {
	WorkerID     string `json:"worker_id"`
	HeartbeatSec int    `json:"heartbeat_sec"`
	Poll         string `json:"poll"`
	// LostTasks lists task IDs this worker still believes it owns that the
	// leader no longer tracks (requeued to someone else or completed after a
	// leader restart). The worker must abort local execution of these
	// instead of reporting into the void (GoMesh/1.1 gap fix).
	LostTasks []string `json:"lost_tasks,omitempty"`
}

// HeartbeatReq parameters for mesh.heartbeat.
type HeartbeatReq struct {
	WorkerID string `json:"worker_id"`
}

// HeartbeatResp result for mesh.heartbeat.
type HeartbeatResp struct {
	OK           bool     `json:"ok"`
	TasksPending int      `json:"tasks_pending"`
	AbortTasks   []string `json:"abort_tasks,omitempty"`
}

// HeartbeatResult alias for HeartbeatResp.
type HeartbeatResult = HeartbeatResp

// ReportMetrics captures task execution metrics.
type ReportMetrics struct {
	Iterations int   `json:"iterations"`
	ElapsedMs  int64 `json:"elapsed_ms"`
}

// ReportReq parameters for mesh.report.
type ReportReq struct {
	WorkerID  string        `json:"worker_id"`
	TaskID    string        `json:"task_id"`
	MissionID string        `json:"mission_id"`
	Success   bool          `json:"success"`
	Result    string        `json:"result"`
	Error     string        `json:"error"`
	Metrics   ReportMetrics `json:"metrics"`
}

// ReportResp result for mesh.report.
type ReportResp struct {
	OK bool `json:"ok"`
}

// WorkerInfo represents a registered cluster worker.
type WorkerInfo struct {
	ID           string    `json:"id"`
	Capabilities []string  `json:"capabilities"`
	LastSeen     time.Time `json:"last_seen"`
	InFlight     int       `json:"in_flight,omitempty"`
	Completed    int       `json:"completed,omitempty"`
	Failed       int       `json:"failed,omitempty"`
	Requeued     int       `json:"requeued,omitempty"`
	Status       string    `json:"status,omitempty"` // "active", "dead"
}

// WorkerStats provides point-in-time statistics for a registered worker.
type WorkerStats struct {
	ID           string    `json:"id"`
	Capabilities []string  `json:"capabilities"`
	LastSeen     time.Time `json:"last_seen"`
	InFlight     int       `json:"in_flight"`
	Completed    int       `json:"completed"`
	Failed       int       `json:"failed"`
	Requeued     int       `json:"requeued"`
	Status       string    `json:"status"`
}

// Task represents an individual assignable unit of work.
type Task struct {
	ID         string    `json:"id"`
	MissionID  string    `json:"mission_id"`
	Goal       string    `json:"goal"`
	Context    string    `json:"context,omitempty"`
	Capability string    `json:"capability,omitempty"`
	TimeoutSec int       `json:"timeout_sec,omitempty"`
	CreatedAt  time.Time `json:"created_at"`
	AssignedTo string    `json:"assigned_to,omitempty"`
	AssignedAt time.Time `json:"assigned_at,omitempty"`
	Attempts   int       `json:"attempts,omitempty"`
}

// SubtaskState tracks subtask progress inside a mission.
type SubtaskState struct {
	ID          string        `json:"id"`
	Goal        string        `json:"goal"`
	Context     string        `json:"context,omitempty"`
	Capability  string        `json:"capability,omitempty"`
	AssignedTo  string        `json:"assigned_to,omitempty"`
	Status      string        `json:"status"` // "pending", "in_flight", "completed", "failed", "cancelled"
	Attempts    int           `json:"attempts"`
	Result      string        `json:"result,omitempty"`
	Error       string        `json:"error,omitempty"`
	Metrics     ReportMetrics `json:"metrics,omitempty"`
	CreatedAt   time.Time     `json:"created_at"`
	AssignedAt  time.Time     `json:"assigned_at,omitempty"`
	CompletedAt time.Time     `json:"completed_at,omitempty"`
}

// MissionState represents an overall high-level mission and its subtasks.
type MissionState struct {
	ID          string         `json:"id"`
	Text        string         `json:"text"`
	Status      string         `json:"status"` // "pending", "in_progress", "completed", "failed", "cancelled"
	Subtasks    []SubtaskState `json:"subtasks"`
	CreatedAt   time.Time      `json:"created_at"`
	CompletedAt time.Time      `json:"completed_at,omitempty"`
	// FinalResult stores the leader's synthesized answer so it survives a
	// leader restart (event-sourced): without it a crash between synthesis
	// and delivery lost the answer forever (GoMesh/1.1 gap fix).
	FinalResult string `json:"final_result,omitempty"`
}

// SubtaskCounts tracks aggregate subtask counts for a mission.
type SubtaskCounts struct {
	Total     int `json:"total"`
	Pending   int `json:"pending"`
	InFlight  int `json:"in_flight"`
	Completed int `json:"completed"`
	Failed    int `json:"failed"`
	Cancelled int `json:"cancelled"`
}

// MissionSummary provides a lightweight snapshot of a mission.
type MissionSummary struct {
	ID            string        `json:"id"`
	Text          string        `json:"text"`
	Status        string        `json:"status"`
	CreatedAt     time.Time     `json:"created_at"`
	CompletedAt   time.Time     `json:"completed_at,omitempty"`
	Subtasks      SubtaskCounts `json:"subtasks"`
	SubtaskCounts SubtaskCounts `json:"subtask_counts"`
}

// TaskSummary represents an individual subtask's status and assignment.
type TaskSummary struct {
	ID         string    `json:"id"`
	Goal       string    `json:"goal"`
	Status     string    `json:"status"`
	AssignedTo string    `json:"assigned_to,omitempty"`
	Attempts   int       `json:"attempts"`
	AssignedAt time.Time `json:"assigned_at,omitempty"`
	Result     string    `json:"result,omitempty"`
	Error      string    `json:"error,omitempty"`
}

// SnapshotData stores complete cluster state for log compaction.
type SnapshotData struct {
	Missions      []*MissionState `json:"missions,omitempty"`
	Tasks         []*Task         `json:"tasks,omitempty"`
	PendingTasks  []*Task         `json:"pending_tasks,omitempty"`
	InFlightTasks []*Task         `json:"in_flight_tasks,omitempty"`
	Workers       []*WorkerInfo   `json:"workers,omitempty"`
}
