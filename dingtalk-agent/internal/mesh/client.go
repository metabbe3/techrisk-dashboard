package mesh

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"strings"
	"sync"
	"time"

	"techrisk-dashboard/dingtalk-agent/internal/agent"
	"techrisk-dashboard/dingtalk-agent/internal/guardrail"
)

// RunFunc is the callback that executes a task locally.
// It decouples the mesh worker from the concrete agent implementation.
type RunFunc func(ctx context.Context, goal string) (string, error)

// PlanFunc decomposes a complex goal into independent sub-goals.
type PlanFunc func(ctx context.Context, goal string) (string, error)

// Client runs a worker node connected to a GoMesh leader.
type Client struct {
	leaderAddr   string
	token        string
	workerID     string
	capabilities []string
	runFunc      RunFunc
	planFunc     PlanFunc
	fanoutMax    int
	httpClient   *http.Client
	backoffBase  time.Duration
	stopChan     chan struct{}
	stopOnce     sync.Once
	mu           sync.Mutex
	runningTasks map[string]context.CancelFunc
	abortedTasks map[string]bool
}

// NewClient initializes a worker client.
func NewClient(leaderAddr, token, workerID string, capabilities []string, runFunc RunFunc) *Client {
	return &Client{
		leaderAddr:   strings.TrimRight(leaderAddr, "/"),
		token:        token,
		workerID:     workerID,
		capabilities: capabilities,
		runFunc:      runFunc,
		fanoutMax:    3, // Default FANOUT_MAX
		httpClient: &http.Client{
			Timeout: 65 * time.Second, // Long-poll is 50s; allow buffer for network
		},
		stopChan:     make(chan struct{}),
		runningTasks: make(map[string]context.CancelFunc),
		abortedTasks: make(map[string]bool),
	}
}

// SetPlanFunc configures the goal decomposition planner for auto-fanout.
func (c *Client) SetPlanFunc(planFunc PlanFunc) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.planFunc = planFunc
}

// SetFanoutMax sets the maximum number of parallel sub-agents spawned for auto-fanout.
// Set to 0 to disable fanout.
func (c *Client) SetFanoutMax(max int) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.fanoutMax = max
}

// Start runs the registration, heartbeat, and long-poll loop until ctx is cancelled.
func (c *Client) Start(ctx context.Context) error {
	log.Printf("[mesh] worker %s starting, connecting to leader at %s", c.workerID, c.leaderAddr)

	// 1. Register with leader (with backoff retry)
	if err := c.registerWithRetry(ctx); err != nil {
		return err
	}

	sessCtx, cancel := context.WithCancel(ctx)
	defer cancel() // heartbeat goroutine mati saat pollLoop keluar
	go c.heartbeatLoop(sessCtx)
	err := c.pollLoop(sessCtx)
	cancel()
	return err
}

// Stop terminates the worker loops.
func (c *Client) Stop() {
	c.stopOnce.Do(func() {
		close(c.stopChan)
	})
	if c.httpClient != nil {
		c.httpClient.CloseIdleConnections()
	}
}

func (c *Client) registerWithRetry(ctx context.Context) error {
	base := c.backoffBase
	if base <= 0 {
		base = 1 * time.Second
	}
	backoff := base
	const maxBackoff = 30 * time.Second

	for {
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-c.stopChan:
			return nil
		default:
		}

		err := c.registerOnce(ctx)
		if err == nil {
			log.Printf("[mesh] worker %s successfully registered with leader", c.workerID)
			return nil
		}

		log.Printf("[mesh] registration failed: %v, retrying in %v...", err, backoff)
		select {
		case <-time.After(backoff):
			backoff *= 2
			if backoff > maxBackoff {
				backoff = maxBackoff
			}
		case <-ctx.Done():
			return ctx.Err()
		case <-c.stopChan:
			return nil
		}
	}
}

func (c *Client) registerOnce(ctx context.Context) error {
	params := RegisterReq{}
	params.Worker.ID = c.workerID
	params.Worker.Capabilities = c.capabilities
	params.KnownTasks = c.KnownRunningTasks() // leader-restart claim check

	rawParams, err := json.Marshal(params)
	if err != nil {
		return err
	}

	reqBody := RPCRequest{
		JSONRPC: "2.0",
		ID:      1,
		Method:  "mesh.register",
		Params:  rawParams,
	}

	var resp RegisterResp
	if err := c.callRPC(ctx, reqBody, &resp); err != nil {
		return err
	}
	// Leader-restart gap fix: if we were mid-task when the leader died and
	// restarted, its replay already requeued our in-flight task to another
	// worker. Abort anything the leader no longer tracks as ours so we do
	// not keep burning a duplicate run whose report would be rejected.
	if len(resp.LostTasks) > 0 {
		c.HandleAbortTasks(resp.LostTasks)
		log.Printf("[mesh] register: leader reported %d lost task(s) — aborted locally", len(resp.LostTasks))
	}
	return nil
}

func (c *Client) heartbeatLoop(ctx context.Context) {
	ticker := time.NewTicker(HeartbeatSec * time.Second)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-c.stopChan:
			return
		case <-ticker.C:
			params := HeartbeatReq{WorkerID: c.workerID}
			rawParams, _ := json.Marshal(params)
			reqBody := RPCRequest{
				JSONRPC: "2.0",
				ID:      time.Now().Unix(),
				Method:  "mesh.heartbeat",
				Params:  rawParams,
			}
			var resp HeartbeatResp
			if err := c.callRPC(ctx, reqBody, &resp); err != nil {
				log.Printf("[mesh] leader unreachable (heartbeat): %v — retry in %v", err, HeartbeatSec*time.Second)
				continue
			}

			if len(resp.AbortTasks) > 0 {
				c.HandleAbortTasks(resp.AbortTasks)
			}
		}
	}
}

// KnownRunningTasks returns the IDs of tasks this worker is currently
// executing (used at re-registration to ask the leader which ones it still
// tracks — leader-restart duplicate-work guard).
func (c *Client) KnownRunningTasks() []string {
	c.mu.Lock()
	defer c.mu.Unlock()
	ids := make([]string, 0, len(c.runningTasks))
	for id := range c.runningTasks {
		ids = append(ids, id)
	}
	return ids
}

// HandleAbortTasks cancels locally running tasks requested by the leader.
func (c *Client) HandleAbortTasks(abortTasks []string) {
	c.mu.Lock()
	defer c.mu.Unlock()

	for _, taskID := range abortTasks {
		if cancel, ok := c.runningTasks[taskID]; ok && cancel != nil {
			log.Printf("[mesh] aborting task %s on leader order", taskID)
			c.abortedTasks[taskID] = true
			cancel()
		}
	}
}

func (c *Client) pollLoop(ctx context.Context) error {
	backoff := 1 * time.Second
	const maxBackoff = 30 * time.Second

	for {
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-c.stopChan:
			return nil
		default:
		}

		task, err := c.fetchNextTask(ctx)
		if err != nil {
			if ctx.Err() != nil {
				return ctx.Err()
			}
			select {
			case <-c.stopChan:
				return nil
			default:
			}
			log.Printf("[mesh] leader unreachable (poll): %v — retry in %v", err, backoff)
			select {
			case <-time.After(backoff):
				backoff *= 2
				if backoff > maxBackoff {
					backoff = maxBackoff
				}
			case <-ctx.Done():
				return ctx.Err()
			case <-c.stopChan:
				return nil
			}
			continue
		}

		// Reset backoff on successful communication
		backoff = 1 * time.Second

		if task == nil {
			// 204 No Content timeout: continue polling immediately
			continue
		}

		// Execute task with per-task deadline
		c.executeAndReportTask(ctx, task)
	}
}

func (c *Client) executeAndReportTask(ctx context.Context, task *Task) {
	log.Printf("[mesh] worker %s executing task %s (goal: %q)", c.workerID, task.ID, task.Goal)
	start := time.Now()

	timeoutSec := task.TimeoutSec
	if timeoutSec <= 0 {
		timeoutSec = DefaultTaskTimeoutSec
	}

	execCtx, cancel := context.WithTimeout(ctx, time.Duration(timeoutSec)*time.Second)
	defer cancel()

	execCtx = context.WithValue(execCtx, agent.TaskIDKey, task.ID)
	execCtx = context.WithValue(execCtx, agent.MissionIDKey, task.MissionID)

	c.mu.Lock()
	c.runningTasks[task.ID] = cancel
	c.mu.Unlock()

	defer func() {
		c.mu.Lock()
		delete(c.runningTasks, task.ID)
		delete(c.abortedTasks, task.ID)
		c.mu.Unlock()
	}()

	result, iterations, execErr := c.runTaskWithFanout(execCtx, task, true)
	elapsed := time.Since(start)

	c.mu.Lock()
	wasAborted := c.abortedTasks[task.ID]
	c.mu.Unlock()

	var errMsg string
	var success bool

	if wasAborted {
		errMsg = "aborted by leader"
		success = false
	} else if execCtx.Err() == context.DeadlineExceeded {
		errMsg = "task deadline exceeded"
		success = false
	} else if execErr != nil {
		errMsg = execErr.Error()
		success = false
	} else {
		success = true
	}

	redactedResult, _ := guardrail.Redact(result)
	redactedErr, _ := guardrail.Redact(errMsg)

	if iterations <= 0 {
		iterations = 1
	}

	report := ReportReq{
		WorkerID:  c.workerID,
		TaskID:    task.ID,
		MissionID: task.MissionID,
		Success:   success,
		Result:    redactedResult,
		Error:     redactedErr,
		Metrics: ReportMetrics{
			Iterations: iterations,
			ElapsedMs:  elapsed.Milliseconds(),
		},
	}

	reportCtx, reportCancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer reportCancel()

	if err := c.reportTask(reportCtx, report); err != nil {
		log.Printf("[mesh] failed to report task %s: %v", task.ID, err)
	} else {
		log.Printf("[mesh] reported task %s successfully (elapsed: %v, success=%v)", task.ID, elapsed, success)
	}
}

func (c *Client) runTaskWithFanout(ctx context.Context, task *Task, allowFanout bool) (string, int, error) {
	if c.runFunc == nil {
		return "", 1, fmt.Errorf("no RunFunc configured on worker")
	}

	result, execErr := c.runFunc(ctx, task.Goal)
	if execErr == nil {
		return result, 1, nil
	}

	// Check if fanout can/should be triggered
	c.mu.Lock()
	fanoutMax := c.fanoutMax
	planFn := c.planFunc
	c.mu.Unlock()

	if !allowFanout || fanoutMax <= 0 || planFn == nil || !isBudgetExhaustion(execErr) {
		return "", 1, execErr
	}

	log.Printf("[mesh] fanout: task %s exhausted iteration budget, decomposing goal", task.ID)

	// Step 1: PLANNER
	planRaw, planErr := planFn(ctx, task.Goal)
	if planErr != nil {
		log.Printf("[mesh] fanout: planner failed: %v, reporting original error", planErr)
		return "", 1, execErr
	}
	planRaw, _ = guardrail.Redact(planRaw)

	subGoals := parseSubGoalsJSON(planRaw)
	if len(subGoals) <= 1 {
		log.Printf("[mesh] fanout: plan yielded %d sub-goals (need >= 2), reporting original error", len(subGoals))
		return "", 1, execErr
	}

	if len(subGoals) > fanoutMax {
		subGoals = subGoals[:fanoutMax]
	}

	for i, sg := range subGoals {
		if flags := guardrail.DocInjectionFlags(sg); len(flags) > 0 {
			log.Printf("[mesh] task goal flagged as potential injection — framed as data")
			subGoals[i] = guardrail.FrameDocumentContent("mesh_task", sg)
		}
	}

	log.Printf("[mesh] fanout: split into %d sub-goals for task %s", len(subGoals), task.ID)

	// Step 2: Parallel execution of sub-goals
	numSub := len(subGoals)
	type subOutcome struct {
		idx    int
		result string
		err    error
	}

	var subTimeout time.Duration
	if deadline, ok := ctx.Deadline(); ok {
		rem := time.Until(deadline)
		if rem > 5*time.Second {
			subTimeout = rem - 2*time.Second
		} else if rem > 0 {
			subTimeout = rem
		}
	}

	outcomes := make([]subOutcome, numSub)
	var wg sync.WaitGroup

	for i, subGoal := range subGoals {
		wg.Add(1)
		go func(idx int, goal string) {
			defer wg.Done()

			var subCtx context.Context
			var subCancel context.CancelFunc
			if subTimeout > 0 {
				subCtx, subCancel = context.WithTimeout(ctx, subTimeout)
			} else {
				subCtx, subCancel = context.WithCancel(ctx)
			}
			defer subCancel()

			log.Printf("[mesh] fanout: executing sub-goal %d/%d for task %s: %q", idx+1, numSub, task.ID, goal)
			// Recursion guard: allowFanout is false for sub-runs!
			res, _, err := c.runSubTask(subCtx, goal)
			outcomes[idx] = subOutcome{
				idx:    idx,
				result: res,
				err:    err,
			}
		}(i, subGoal)
	}

	wg.Wait()

	// Step 3 & 4: Combine results and compute metrics
	var successResults []string
	var failedGoals []string
	successCount := 0
	var firstErr error

	for i, o := range outcomes {
		if o.err == nil {
			successCount++
			successResults = append(successResults, fmt.Sprintf("## Sub-result %d\n%s", i+1, o.result))
		} else {
			if firstErr == nil {
				firstErr = o.err
			}
			redactedGoal, _ := guardrail.Redact(subGoals[i])
			failedGoals = append(failedGoals, redactedGoal)
			log.Printf("[mesh] fanout: sub-goal %d failed for task %s: %v", i+1, task.ID, o.err)
		}
	}

	log.Printf("[mesh] fanout: completed %d/%d sub-goals for task %s", successCount, numSub, task.ID)

	if successCount == 0 {
		if firstErr != nil {
			return "", 0, fmt.Errorf("all fanout sub-goals failed: %w", firstErr)
		}
		return "", 0, execErr
	}

	combined := strings.Join(successResults, "\n\n")
	if successCount < numSub {
		header := fmt.Sprintf("[partial: %d/%d sub-goals succeeded; failed: %s]\n\n", successCount, numSub, strings.Join(failedGoals, "; "))
		combined = header + combined
	}
	return combined, successCount, nil
}

func (c *Client) runSubTask(ctx context.Context, goal string) (string, int, error) {
	if c.runFunc == nil {
		return "", 0, fmt.Errorf("no RunFunc configured")
	}
	res, err := c.runFunc(ctx, goal)
	return res, 1, err
}

func isBudgetExhaustion(err error) bool {
	if err == nil {
		return false
	}
	s := strings.ToLower(err.Error())
	return strings.Contains(s, "iteration budget") ||
		strings.Contains(s, "max iterations") ||
		strings.Contains(s, "budget")
}

func parseSubGoalsJSON(raw string) []string {
	raw = strings.TrimSpace(raw)
	if strings.HasPrefix(raw, "```") {
		idx := strings.Index(raw, "\n")
		if idx != -1 {
			raw = raw[idx+1:]
		}
		if lastIdx := strings.LastIndex(raw, "```"); lastIdx != -1 {
			raw = raw[:lastIdx]
		}
		raw = strings.TrimSpace(raw)
	}

	start := strings.Index(raw, "[")
	end := strings.LastIndex(raw, "]")
	if start == -1 || end == -1 || end <= start {
		return nil
	}
	sliceJSON := raw[start : end+1]

	var items []string
	if err := json.Unmarshal([]byte(sliceJSON), &items); err != nil {
		return nil
	}

	var cleaned []string
	for _, it := range items {
		it = strings.TrimSpace(it)
		if it != "" {
			cleaned = append(cleaned, it)
		}
	}
	return cleaned
}

func (c *Client) fetchNextTask(ctx context.Context) (*Task, error) {
	url := fmt.Sprintf("%s/nexttask?id=%s", c.leaderAddr, c.workerID)
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil, err
	}

	req.Header.Set(HeaderMeshToken, c.token)
	req.Header.Set(HeaderMeshProto, ProtoVersion)

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	if resp.StatusCode == http.StatusNoContent {
		return nil, nil
	}
	if resp.StatusCode == http.StatusUnauthorized {
		return nil, fmt.Errorf("leader authentication failed: 401 Unauthorized")
	}
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("unexpected status from leader: HTTP %d", resp.StatusCode)
	}

	var task Task
	if err := json.NewDecoder(resp.Body).Decode(&task); err != nil {
		return nil, fmt.Errorf("failed to decode task: %w", err)
	}
	return &task, nil
}

func (c *Client) reportTask(ctx context.Context, report ReportReq) error {
	rawParams, err := json.Marshal(report)
	if err != nil {
		return err
	}

	reqBody := RPCRequest{
		JSONRPC: "2.0",
		ID:      time.Now().Unix(),
		Method:  "mesh.report",
		Params:  rawParams,
	}

	var resp ReportResp
	return c.callRPC(ctx, reqBody, &resp)
}

func (c *Client) callRPC(ctx context.Context, req RPCRequest, resultTarget any) error {
	data, err := json.Marshal(req)
	if err != nil {
		return err
	}

	url := c.leaderAddr + "/rpc"
	httpReq, err := http.NewRequestWithContext(ctx, http.MethodPost, url, bytes.NewReader(data))
	if err != nil {
		return err
	}

	httpReq.Header.Set("Content-Type", "application/json")
	httpReq.Header.Set(HeaderMeshToken, c.token)
	httpReq.Header.Set(HeaderMeshProto, ProtoVersion)

	resp, err := c.httpClient.Do(httpReq)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	if resp.StatusCode == http.StatusUnauthorized {
		return fmt.Errorf("leader authentication failed: 401 Unauthorized")
	}
	if resp.StatusCode != http.StatusOK {
		body, _ := io.ReadAll(resp.Body)
		return fmt.Errorf("rpc HTTP error %d: %s", resp.StatusCode, string(body))
	}

	var rpcResp RPCResponse
	if err := json.NewDecoder(resp.Body).Decode(&rpcResp); err != nil {
		return fmt.Errorf("failed to decode rpc response: %w", err)
	}

	if rpcResp.Error != nil {
		return fmt.Errorf("rpc error %d: %s", rpcResp.Error.Code, rpcResp.Error.Message)
	}

	if resultTarget != nil && rpcResp.Result != nil {
		rawRes, err := json.Marshal(rpcResp.Result)
		if err != nil {
			return err
		}
		if err := json.Unmarshal(rawRes, resultTarget); err != nil {
			return fmt.Errorf("failed to parse rpc result: %w", err)
		}
	}
	return nil
}
