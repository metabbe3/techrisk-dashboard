package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"log"
	"net/http"
	"os"
	"os/signal"
	"strings"
	"syscall"
	"time"

	dingtalk_stream "github.com/open-dingtalk/dingtalk-stream-sdk-go/client"

	"techrisk-dashboard/dingtalk-agent/internal/agent"
	"techrisk-dashboard/dingtalk-agent/internal/config"
	"techrisk-dashboard/dingtalk-agent/internal/dingtalk"
	"techrisk-dashboard/dingtalk-agent/internal/guardrail"
	"techrisk-dashboard/dingtalk-agent/internal/llm"
	"techrisk-dashboard/dingtalk-agent/internal/mesh"
	"techrisk-dashboard/dingtalk-agent/internal/tools"
)

const version = "0.5.0"

func main() {
	// Ops one-shot: ./agent -dm staffId1,staffId2 -dm-title T -dm-text "hello"
	dmTo := flag.String("dm", "", "comma-separated staffIds to push a DM to, then exit")
	dmTitle := flag.String("dm-title", "Risk Agent", "DM title (-dm mode)")
	dmText := flag.String("dm-text", "Hello from Risk Agent.", "DM markdown text (-dm mode)")
	flag.Parse()

	cfg, err := config.Load()
	if err != nil {
		log.Fatalf("Configuration error: %v", err)
	}

	if *dmTo != "" {
		staffIDs := strings.Split(*dmTo, ",")
		if err := dingtalk.SendMarkdownDM(context.Background(), cfg, staffIDs, *dmTitle, *dmText); err != nil {
			log.Fatalf("DM send failed: %v", err)
		}
		log.Printf("DM sent to %d recipient(s).", len(staffIDs))
		return
	}

	if cfg.MeshRole != "solo" {
		log.Printf("[mesh] Loaded config: Role=%s, LLM Model=%s, BaseURL=%s, MaxIter=%d",
			cfg.MeshRole, cfg.LLMModel, cfg.LLMBaseURL, cfg.MaxIterations)
	} else {
		log.Printf("Starting dingtalk-agent v%s (GoAgent fork)...", version)
	}

	if len(cfg.DingTalkAllowlist) == 0 {
		log.Printf("DINGTALK_ALLOWLIST empty — answering EVERYONE who can reach the bot (gated by the DingTalk app visibility range).")
	}

	log.Printf("Config: model=%s, api_base=%s, allowlist=%d sender(s), max_iter=%d, max_tokens=%d, web=%v, notion=%v (%d seed database(s))",
		cfg.LLMModel, cfg.DashboardAPIBase, len(cfg.DingTalkAllowlist), cfg.MaxIterations, cfg.LLMMaxTokens, cfg.WebToolsEnabled, cfg.NotionToolsEnabled, len(cfg.NotionDatabases))

	llmClient := llm.NewClient(cfg)
	toolRegistry := tools.NewRegistry(cfg)
	agentLoop := agent.NewLoop(llmClient, toolRegistry, cfg.MaxIterations)
	agentLoop.SetRole(cfg.MeshRole)

	ctx, cancel := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer cancel()

	hasDingTalk := cfg.DingTalkClientID != "" && cfg.DingTalkClientSecret != ""

	runDingTalk := func() {
		bot := dingtalk.NewBot(cfg, agentLoop)

		options := []dingtalk_stream.ClientOption{
			dingtalk_stream.WithAppCredential(
				dingtalk_stream.NewAppCredentialConfig(cfg.DingTalkClientID, cfg.DingTalkClientSecret),
			),
			dingtalk_stream.WithAutoReconnect(true),
		}
		if cfg.DingTalkOpenAPIHost != "" {
			options = append(options, dingtalk_stream.WithOpenApiHost(cfg.DingTalkOpenAPIHost))
		}

		cli := dingtalk_stream.NewStreamClient(options...)
		cli.RegisterChatBotCallbackRouter(bot.Handle)

		// Chat reminders now live in the dashboard (AiAgent rows) — dispatched by
		// its scheduler and executed back here via the internal listener.
		go bot.RunWatchPoller(ctx) // incident status watches (data/watches.json)

		// Internal listener — the dashboard's engine endpoint (RunAiAgentJob POSTs
		// /internal/run here). Started only when the shared token is configured.
		var internalSrv *http.Server
		if cfg.InternalToken != "" {
			internalSrv = &http.Server{
				Addr:              cfg.InternalAddr,
				Handler:           dingtalk.NewInternalMux(bot),
				ReadHeaderTimeout: 5 * time.Second,
			}
			go func() {
				log.Printf("internal listener on %s (dashboard engine endpoint)", cfg.InternalAddr)
				if err := internalSrv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
					log.Printf("internal listener failed: %v", err)
				}
			}()
		} else {
			log.Printf("AGENT_INTERNAL_TOKEN empty — internal listener disabled (scheduled agents cannot use the Go engine).")
		}

		log.Printf("dingtalk-agent online — stream connecting, waiting for messages.")
		if err := cli.Start(ctx); err != nil {
			log.Fatalf("stream client error: %v", err)
		}
		// Start() only dials and spawns the read loop; block until shutdown.
		<-ctx.Done()

		// Graceful drain: let in-flight multi-tool turns finish (bounded) so a
		// restart does not sever answers mid-generation (audit round 4).
		if bot != nil {
			if bot.Drain(15 * time.Second) {
				log.Printf("drain complete — no agent turns in flight")
			} else {
				log.Printf("drain timed out after 15s — exiting with turns still running")
			}
		}

		if internalSrv != nil {
			shutdownCtx, shutdownCancel := context.WithTimeout(context.Background(), 5*time.Second)
			defer shutdownCancel()
			if err := internalSrv.Shutdown(shutdownCtx); err != nil {
				log.Printf("internal listener shutdown: %v", err)
			}
		}
	}

	switch cfg.MeshRole {
	case "leader":
		if cfg.MeshToken == "" {
			log.Fatalf("[mesh] GOAGENT_MESH_TOKEN is required for leader mode")
		}
		log.Printf("[mesh] starting dingtalk-agent in LEADER mode on %s...", cfg.MeshListen)
		queue := mesh.NewQueue(cfg.DataDir, cfg.TaskTimeout, cfg.MissionTimeout, cfg.SubtaskAttempts)
		server := mesh.NewServer(cfg.MeshListen, cfg.MeshToken, queue)

		// Register leader orchestration tools for LLM
		mesh.RegisterLeaderTools(toolRegistry, server)

		if err := server.Start(); err != nil {
			log.Fatalf("[mesh] fatal mesh leader server error: %v", err)
		}
		defer func() {
			stopCtx, stopCancel := context.WithTimeout(context.Background(), 2*time.Second)
			defer stopCancel()
			_ = server.Stop(stopCtx)
		}()

		if hasDingTalk {
			log.Printf("[mesh] DingTalk credentials found: starting dual DingTalk bot on leader...")
			runDingTalk()
		} else {
			log.Printf("[mesh] mesh leader running without DingTalk bot. Press Ctrl+C to stop.")
			<-ctx.Done()
		}

	case "worker":
		if cfg.MeshToken == "" {
			log.Fatalf("[mesh] GOAGENT_MESH_TOKEN is required for worker mode")
		}
		if cfg.LeaderAddr == "" {
			log.Fatalf("[mesh] GOAGENT_LEADER_ADDR is required for worker mode")
		}
		if os.Getenv("GOAGENT_SKIP_LLM_CHECK") != "true" {
			if err := llmClient.Ping(ctx); err != nil {
				log.Fatalf("[mesh] LLM sanity check failed (LLM_BASE_URL/LLM_API_KEY/LLM_MODEL): %v", err)
			}
		}
		log.Printf("[mesh] starting dingtalk-agent in WORKER mode (ID: %s, Caps: %v)...", cfg.WorkerID, cfg.Capabilities)
		runFunc := func(taskCtx context.Context, goal string) (string, error) {
			if flags := guardrail.DocInjectionFlags(goal); len(flags) > 0 {
				log.Printf("[mesh] task goal flagged as potential injection — framed as data")
				goal = guardrail.FrameDocumentContent("mesh_task", goal)
			}
			reply, _, _, err := agentLoop.Run(taskCtx, goal, nil, "")
			return reply, err
		}

		client := mesh.NewClient(cfg.LeaderAddr, cfg.MeshToken, cfg.WorkerID, cfg.Capabilities, runFunc)
		client.SetFanoutMax(cfg.FanoutMax)
		if cfg.FanoutMax > 0 {
			client.SetPlanFunc(func(planCtx context.Context, goal string) (string, error) {
				if flags := guardrail.DocInjectionFlags(goal); len(flags) > 0 {
					log.Printf("[mesh] task goal flagged as potential injection — framed as data")
					goal = guardrail.FrameDocumentContent("mesh_task", goal)
				}
				prompt := fmt.Sprintf("Split this goal into at most %d independent sub-goals. Reply with ONE JSON array of strings only.\n\nGoal: %s", cfg.FanoutMax, goal)
				msg, _, err := llmClient.ChatCompletion(planCtx, []llm.ChatMessage{
					{Role: "user", Content: prompt},
				}, nil)
				if err != nil {
					return "", err
				}
				return msg.Content, nil
			})
		}
		defer client.Stop()

		go func() {
			for {
				if err := client.Start(ctx); err != nil && ctx.Err() == nil {
					log.Printf("[mesh] worker error: %v, reconnecting in 5s...", err)
					select {
					case <-time.After(5 * time.Second):
					case <-ctx.Done():
						return
					}
				} else {
					return
				}
			}
		}()

		if hasDingTalk {
			log.Printf("[mesh] DingTalk credentials found: starting dual DingTalk bot on worker...")
			runDingTalk()
		} else {
			log.Printf("[mesh] mesh worker running. Polling tasks from leader. Press Ctrl+C to stop.")
			<-ctx.Done()
		}

	default: // "solo"
		runDingTalk()
	}

	log.Printf("dingtalk-agent exited cleanly.")
}
