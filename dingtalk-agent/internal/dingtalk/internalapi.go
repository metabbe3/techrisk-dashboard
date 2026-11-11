package dingtalk

import (
	"context"
	"crypto/subtle"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"time"

	"github.com/open-dingtalk/dingtalk-stream-sdk-go/chatbot"

	"techrisk-dashboard/dingtalk-agent/internal/guardrail"
	"techrisk-dashboard/dingtalk-agent/internal/llm"
	"techrisk-dashboard/dingtalk-agent/internal/tools"
)

// The internal listener is the dashboard's engine endpoint. RunAiAgentJob
// POSTs /internal/run here (engine=go → mode "task", engine=static → mode
// "message") over the compose network, guarded by the shared X-Agent-Token
// secret. It is started from main only when AGENT_INTERNAL_TOKEN is set.

// internalRunRequest is the dashboard's RunAiAgentJob payload (run_id, agent_id
// and model are sent too but are the dashboard's own bookkeeping — the Go
// engine runs its configured model).
type internalRunRequest struct {
	AgentName        string `json:"agent_name"`
	Mode             string `json:"mode"` // "task" (agent loop) | "message" (deliver text, no LLM)
	System           string `json:"system,omitempty"`
	Text             string `json:"text"`
	ConversationType string `json:"conversation_type,omitempty"`
	ConversationID   string `json:"conversation_id,omitempty"`
	StaffID          string `json:"staff_id,omitempty"`
}

// internalRunResult is the response contract runViaGoEngine reads (RunAiAgentJob).
type internalRunResult struct {
	OK             bool       `json:"ok"`
	Output         string     `json:"output,omitempty"`
	Model          string     `json:"model,omitempty"`
	Usage          *llm.Usage `json:"usage,omitempty"`
	ResponseTimeMs int64      `json:"response_time_ms"`
	Delivered      bool       `json:"delivered,omitempty"`
	DeliveryError  string     `json:"delivery_error,omitempty"`
	Error          string     `json:"error,omitempty"`
}

// NewInternalMux serves the internal listener: POST /internal/run only.
func NewInternalMux(b *Bot) *http.ServeMux {
	mux := http.NewServeMux()
	mux.HandleFunc("/internal/run", func(w http.ResponseWriter, r *http.Request) {
		defer func() {
			if rec := recover(); rec != nil {
				log.Printf("[internal] run handler panic: %v", rec)
				writeInternalJSON(w, http.StatusOK, internalRunResult{OK: false, Error: fmt.Sprintf("internal run panicked: %v", rec)})
			}
		}()

		if r.Method != http.MethodPost {
			http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
			return
		}
		token := r.Header.Get("X-Agent-Token")
		if token == "" || subtle.ConstantTimeCompare([]byte(token), []byte(b.cfg.InternalToken)) != 1 {
			http.Error(w, "unauthorized", http.StatusUnauthorized)
			return
		}

		body, err := io.ReadAll(io.LimitReader(r.Body, 1<<20))
		if err != nil {
			http.Error(w, "bad request", http.StatusBadRequest)
			return
		}
		var req internalRunRequest
		if err := json.Unmarshal(body, &req); err != nil {
			http.Error(w, "bad json", http.StatusBadRequest)
			return
		}

		writeInternalJSON(w, http.StatusOK, b.runInternal(r.Context(), req))
	})
	return mux
}

// runInternal executes one dashboard-requested run. The reply is delivered to
// the bound DingTalk conversation BEFORE the response is written, so the
// `delivered` flag is a fact, not an intention.
func (b *Bot) runInternal(ctx context.Context, req internalRunRequest) internalRunResult {
	started := time.Now()

	switch req.Mode {
	case "message":
		// Static engine: the text is delivered as-is — no LLM, no filtering
		// (the dashboard authored it).
		delivered, derr := b.deliverInternal(ctx, req, req.Text)
		return internalRunResult{
			OK:             true,
			Output:         req.Text,
			ResponseTimeMs: time.Since(started).Milliseconds(),
			Delivered:      delivered,
			DeliveryError:  derr,
		}

	case "task":
		// Shares the 4-slot concurrency ceiling with chat turns.
		b.sem <- struct{}{}
		runCtx, cancel := context.WithTimeout(ctx, 360*time.Second)
		defer func() { <-b.sem; cancel() }()

		// The dashboard's System prompt rides as sysExtra (appended to the
		// loop's own system prompt) — history is never trusted to carry one.
		turnCtx := tools.WithNotionSink(runCtx)
		reply, _, usage, err := b.loop.Run(turnCtx, req.Text, nil, req.System)
		tools.TakeNotionReads(turnCtx) // a scheduled run must not leak reads into the next chat turn
		if err != nil {
			return internalRunResult{
				OK:             false,
				Error:          err.Error(),
				Usage:          usagePtr(usage),
				ResponseTimeMs: time.Since(started).Milliseconds(),
			}
		}

		filtered := guardrail.FilterReplyCap(reply, b.cfg.MaxReplyChars)
		if filtered == "" {
			filtered = "The scheduled run produced no answer."
		}
		if req.ConversationID != "" {
			// Generated files (PDFs/decks) ride the same delivery path as chats.
			filtered = b.deliverGenerated(runCtx, internalCallback(req), filtered)
		}
		delivered, derr := b.deliverInternal(ctx, req, filtered)
		return internalRunResult{
			OK:             true,
			Output:         filtered,
			Model:          b.llm.Model(),
			Usage:          usagePtr(usage),
			ResponseTimeMs: time.Since(started).Milliseconds(),
			Delivered:      delivered,
			DeliveryError:  derr,
		}

	default:
		return internalRunResult{
			OK:    false,
			Error: fmt.Sprintf("unknown mode %q (want \"task\" or \"message\")", req.Mode),
		}
	}
}

// deliverInternal pushes the final text to the bound conversation (group first,
// DM fallback — same routing as reminder delivery). Unbound runs skip delivery
// (the dashboard keeps the output in the run row).
func (b *Bot) deliverInternal(ctx context.Context, req internalRunRequest, text string) (bool, string) {
	if req.ConversationID == "" {
		return false, ""
	}
	title := orDefault(req.AgentName, "Risk Agent")
	if err := b.deliverMarkdown(ctx, req.ConversationID, req.ConversationType, req.StaffID, title, text); err != nil {
		return false, err.Error()
	}
	return true, ""
}

// internalCallback synthesizes the minimal callback model deliverGenerated
// needs (conversation routing for generated files).
func internalCallback(req internalRunRequest) *chatbot.BotCallbackDataModel {
	return &chatbot.BotCallbackDataModel{
		ConversationType: req.ConversationType,
		ConversationId:   req.ConversationID,
		SenderStaffId:    req.StaffID,
	}
}

func usagePtr(u llm.Usage) *llm.Usage {
	if u == (llm.Usage{}) {
		return nil
	}
	return &u
}

func writeInternalJSON(w http.ResponseWriter, code int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(v)
}
