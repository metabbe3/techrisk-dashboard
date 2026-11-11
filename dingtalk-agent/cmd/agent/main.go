package main

import (
	"context"
	"errors"
	"flag"
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
	"techrisk-dashboard/dingtalk-agent/internal/llm"
	"techrisk-dashboard/dingtalk-agent/internal/tools"
)

const version = "0.1.0"

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

	log.Printf("Starting dingtalk-agent v%s (GoAgent fork)...", version)

	if len(cfg.DingTalkAllowlist) == 0 {
		log.Printf("DINGTALK_ALLOWLIST empty — answering EVERYONE who can reach the bot (gated by the DingTalk app visibility range).")
	}

	log.Printf("Config: model=%s, api_base=%s, allowlist=%d sender(s), max_iter=%d, max_tokens=%d, web=%v, notion=%v (%d seed database(s))",
		cfg.LLMModel, cfg.DashboardAPIBase, len(cfg.DingTalkAllowlist), cfg.MaxIterations, cfg.LLMMaxTokens, cfg.WebToolsEnabled, cfg.NotionToolsEnabled, len(cfg.NotionDatabases))

	llmClient := llm.NewClient(cfg)
	toolRegistry := tools.NewRegistry(cfg)
	agentLoop := agent.NewLoop(llmClient, toolRegistry, cfg.MaxIterations)
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

	ctx, cancel := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer cancel()

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

	if internalSrv != nil {
		shutdownCtx, shutdownCancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer shutdownCancel()
		if err := internalSrv.Shutdown(shutdownCtx); err != nil {
			log.Printf("internal listener shutdown: %v", err)
		}
	}

	log.Printf("dingtalk-agent exited cleanly.")
}
