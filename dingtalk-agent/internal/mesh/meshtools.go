package mesh

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"

	"techrisk-dashboard/dingtalk-agent/internal/llm"
	"techrisk-dashboard/dingtalk-agent/internal/tools"
)

// RegisterLeaderTools registers GoMesh coordinator tools into the registry.
// Only called when GOAGENT_ROLE=leader.
func RegisterLeaderTools(r *tools.Registry, s *Server) {
	q := s.Queue()

	// 1. delegate_task
	r.Register(llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name: "delegate_task",
			Description: "Delegate a complex mission into independent subtasks assigned to cluster workers. " +
				"Returns mission ID and assigned subtask IDs.",
			Parameters: map[string]any{
				"type": "object",
				"properties": map[string]any{
					"mission": map[string]any{
						"type":        "string",
						"description": "High-level description of the overall objective",
					},
					"subtasks": map[string]any{
						"type":        "array",
						"description": "List of distinct subtasks to assign to workers",
						"items": map[string]any{
							"type": "object",
							"properties": map[string]any{
								"goal":       map[string]any{"type": "string", "description": "Specific goal for this subtask"},
								"context":    map[string]any{"type": "string", "description": "Background info or parameters"},
								"capability": map[string]any{"type": "string", "description": "Optional required worker capability (e.g. 'logs', 'shell')"},
							},
							"required": []string{"goal"},
						},
					},
				},
				"required": []string{"mission", "subtasks"},
			},
		},
	}, func(_ context.Context, argsJSON string) (string, error) {
		var args struct {
			Mission  string         `json:"mission"`
			Subtasks []SubtaskInput `json:"subtasks"`
		}
		if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
			return "", fmt.Errorf("invalid arguments: %w", err)
		}
		if len(args.Subtasks) == 0 {
			return "", fmt.Errorf("subtasks list cannot be empty")
		}

		m, err := q.AddMission(args.Mission, args.Subtasks)
		if err != nil {
			return "", err
		}

		var ids []string
		for _, st := range m.Subtasks {
			ids = append(ids, st.ID)
		}
		return fmt.Sprintf("Mission %s created with %d subtasks: %s. Use mission_status or collect_results to track progress.",
			m.ID, len(m.Subtasks), strings.Join(ids, ", ")), nil
	})

	// 2. list_workers
	r.Register(llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name:        "list_workers",
			Description: "List all registered cluster workers, their capabilities, status, in-flight tasks, and last seen time.",
			Parameters: map[string]any{
				"type":       "object",
				"properties": map[string]any{},
			},
		},
	}, func(_ context.Context, _ string) (string, error) {
		workers := q.ListWorkers()
		if len(workers) == 0 {
			return "No workers currently registered in GoMesh cluster.", nil
		}
		var sb strings.Builder
		sb.WriteString("GoMesh Cluster Workers:\n")
		for _, w := range workers {
			caps := strings.Join(w.Capabilities, ", ")
			if caps == "" {
				caps = "general"
			}
			fmt.Fprintf(&sb, "- Worker %s: status=%s, in_flight=%d, caps=[%s], last_seen=%s\n",
				w.ID, w.Status, w.InFlight, caps, w.LastSeen.Format("15:04:05 UTC"))
		}
		return strings.TrimSpace(sb.String()), nil
	})

	// 3. mission_status
	r.Register(llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name:        "mission_status",
			Description: "Get complete status and progress of a mission and all its subtasks.",
			Parameters: map[string]any{
				"type": "object",
				"properties": map[string]any{
					"mission_id": map[string]any{"type": "string", "description": "Mission ID returned by delegate_task"},
				},
				"required": []string{"mission_id"},
			},
		},
	}, func(_ context.Context, argsJSON string) (string, error) {
		var args struct {
			MissionID string `json:"mission_id"`
		}
		if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
			return "", fmt.Errorf("invalid arguments: %w", err)
		}
		m, err := q.MissionStatus(args.MissionID)
		if err != nil {
			return "", err
		}
		data, err := json.MarshalIndent(m, "", "  ")
		if err != nil {
			return "", err
		}
		return string(data), nil
	})

	// 4. cancel_mission
	r.Register(llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name:        "cancel_mission",
			Description: "Cancel an active or pending mission and its remaining subtasks.",
			Parameters: map[string]any{
				"type": "object",
				"properties": map[string]any{
					"mission_id": map[string]any{"type": "string", "description": "Mission ID to cancel"},
				},
				"required": []string{"mission_id"},
			},
		},
	}, func(_ context.Context, argsJSON string) (string, error) {
		var args struct {
			MissionID string `json:"mission_id"`
		}
		if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
			return "", fmt.Errorf("invalid arguments: %w", err)
		}
		if err := q.CancelMission(args.MissionID); err != nil {
			return "", err
		}
		return fmt.Sprintf("Mission %s cancelled successfully.", args.MissionID), nil
	})

	// 5. collect_results
	r.Register(llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name:        "collect_results",
			Description: "Collect and synthesize findings from all completed subtasks of a mission.",
			Parameters: map[string]any{
				"type": "object",
				"properties": map[string]any{
					"mission_id": map[string]any{"type": "string", "description": "Mission ID to collect results for"},
				},
				"required": []string{"mission_id"},
			},
		},
	}, func(_ context.Context, argsJSON string) (string, error) {
		var args struct {
			MissionID string `json:"mission_id"`
		}
		if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
			return "", fmt.Errorf("invalid arguments: %w", err)
		}
		return q.CollectResults(args.MissionID)
	})

	// 6. set_mission_result — persist the leader's final synthesized answer
	// (survives leader restarts via the event log; GoMesh/1.1 gap fix).
	r.Register(llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name: "set_mission_result",
			Description: "Persist the final synthesized answer for a mission AFTER collect_results. " +
				"Always call this once the final answer is composed, so it survives a leader restart.",
			Parameters: map[string]any{
				"type": "object",
				"properties": map[string]any{
					"mission_id": map[string]any{"type": "string", "description": "Mission ID"},
					"result":     map[string]any{"type": "string", "description": "The final synthesized answer text"},
				},
				"required": []string{"mission_id", "result"},
			},
		},
	}, func(_ context.Context, argsJSON string) (string, error) {
		var args struct {
			MissionID string `json:"mission_id"`
			Result    string `json:"result"`
		}
		if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
			return "", fmt.Errorf("invalid arguments: %w", err)
		}
		if args.MissionID == "" || strings.TrimSpace(args.Result) == "" {
			return "", fmt.Errorf("mission_id and result are required")
		}
		if err := q.SetMissionResult(args.MissionID, args.Result); err != nil {
			return "", err
		}
		return "Mission result persisted.", nil
	})

	// 7. mesh_info
	r.Register(llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name:        "mesh_info",
			Description: "Get cluster status: role, wire protocol version, listen address, and connected worker count.",
			Parameters: map[string]any{
				"type":       "object",
				"properties": map[string]any{},
			},
		},
	}, func(_ context.Context, _ string) (string, error) {
		workers := q.ListWorkers()
		pending, inFlight := q.Stats()
		info := map[string]any{
			"role":            "leader",
			"protocol":        MeshProtocol,
			"listen":          s.Addr(),
			"workers_total":   len(workers),
			"pending_tasks":   pending,
			"in_flight_tasks": inFlight,
		}
		data, _ := json.MarshalIndent(info, "", "  ")
		return string(data), nil
	})
}
