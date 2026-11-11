package tools

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"

	"techrisk-dashboard/dingtalk-agent/internal/config"
)

func TestScheduleAPICreateCron(t *testing.T) {
	var mu sync.Mutex
	var gotPath, gotMethod, gotAuth string
	var gotBody map[string]any

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		defer mu.Unlock()
		gotPath, gotMethod, gotAuth = r.URL.Path, r.Method, r.Header.Get("Authorization")
		gotBody = map[string]any{}
		_ = json.NewDecoder(r.Body).Decode(&gotBody)
		envelope(w, map[string]any{
			"no": 4, "mode": "task", "text": "list open P1 incidents",
			"cron": "0 9 * * 1", "next_run_at": "2026-11-16T09:00:00+07:00",
		}, nil)
	}))
	defer srv.Close()

	api := NewScheduleAPI(&config.Config{DashboardAPIBase: srv.URL, DashboardAPIToken: "tok"})
	it, err := api.Create(context.Background(), "cid-1", "2", "s1", "Nicholas", "task", "0 9 * * 1", nil, "list open P1 incidents")
	if err != nil {
		t.Fatal(err)
	}

	mu.Lock()
	defer mu.Unlock()
	if gotPath != "/agent-schedules" || gotMethod != http.MethodPost || gotAuth != "Bearer tok" {
		t.Errorf("call = %s %s auth %q", gotMethod, gotPath, gotAuth)
	}
	for key, want := range map[string]any{
		"conversation_id": "cid-1", "conversation_type": "2", "staff_id": "s1",
		"nick": "Nicholas", "mode": "task", "cron": "0 9 * * 1", "text": "list open P1 incidents",
	} {
		if gotBody[key] != want {
			t.Errorf("body[%s] = %v, want %v", key, gotBody[key], want)
		}
	}
	if _, has := gotBody["run_at"]; has {
		t.Error("a cron create must not send run_at")
	}
	if want := "#4 cron '0 9 * * 1' (next Mon 2026-11-16 09:00 WIB) task: list open P1 incidents"; it.Describe() != want {
		t.Errorf("Describe() = %q, want %q", it.Describe(), want)
	}
}

func TestScheduleAPICreateOneShot(t *testing.T) {
	var mu sync.Mutex
	var gotBody map[string]any
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		defer mu.Unlock()
		gotBody = map[string]any{}
		_ = json.NewDecoder(r.Body).Decode(&gotBody)
		envelope(w, map[string]any{"no": 1, "mode": "message", "text": "check the P1s", "next_run_at": "2026-11-16T09:00:00+07:00"}, nil)
	}))
	defer srv.Close()

	at := time.Date(2026, 11, 16, 9, 0, 0, 0, time.FixedZone("WIB", 7*3600))
	api := NewScheduleAPI(&config.Config{DashboardAPIBase: srv.URL, DashboardAPIToken: "tok"})
	if _, err := api.Create(context.Background(), "cid-1", "1", "s1", "Nicholas", "message", "", &at, "check the P1s"); err != nil {
		t.Fatal(err)
	}

	mu.Lock()
	defer mu.Unlock()
	if gotBody["run_at"] != "2026-11-16T09:00:00+07:00" {
		t.Errorf("run_at = %v, want the resolved one-shot instant", gotBody["run_at"])
	}
	if _, has := gotBody["cron"]; has {
		t.Error("a one-shot create must not send cron")
	}
}

func TestScheduleAPIList(t *testing.T) {
	var mu sync.Mutex
	var gotQuery string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		defer mu.Unlock()
		gotQuery = r.URL.RawQuery
		envelope(w, []map[string]any{{
			"no": 1, "mode": "message", "text": "review open P1 incidents",
			"cron": "0 9 * * 1", "next_run_at": "2026-11-16T09:00:00+07:00",
		}}, nil)
	}))
	defer srv.Close()

	api := NewScheduleAPI(&config.Config{DashboardAPIBase: srv.URL, DashboardAPIToken: "tok"})
	items, err := api.List(context.Background(), "cid group/1")
	if err != nil {
		t.Fatal(err)
	}

	mu.Lock()
	defer mu.Unlock()
	if !strings.Contains(gotQuery, "conversation_id=cid+group%2F1") {
		t.Errorf("query = %q, want the conversation id URL-escaped", gotQuery)
	}
	if len(items) != 1 || items[0].No != 1 || items[0].Cron == nil || *items[0].Cron != "0 9 * * 1" {
		t.Errorf("items = %+v", items)
	}
}

func TestScheduleAPICancel(t *testing.T) {
	var mu sync.Mutex
	var gotPath, gotMethod string
	var gotBody map[string]any
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		defer mu.Unlock()
		gotPath, gotMethod = r.URL.Path, r.Method
		gotBody = map[string]any{}
		_ = json.NewDecoder(r.Body).Decode(&gotBody)
		envelope(w, nil, nil)
	}))
	defer srv.Close()

	api := NewScheduleAPI(&config.Config{DashboardAPIBase: srv.URL, DashboardAPIToken: "tok"})
	if err := api.Cancel(context.Background(), "cid-1", 3); err != nil {
		t.Fatal(err)
	}

	mu.Lock()
	defer mu.Unlock()
	if gotPath != "/agent-schedules/3" || gotMethod != http.MethodDelete {
		t.Errorf("call = %s %s, want DELETE /agent-schedules/3", gotMethod, gotPath)
	}
	if gotBody["conversation_id"] != "cid-1" {
		t.Errorf("body = %v, want the conversation id", gotBody)
	}
}

// A 500 from the dashboard surfaces as an error (fail-closed upstream).
func TestScheduleAPIErrorSurfaces(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
		_, _ = w.Write([]byte(fmt.Sprintf(`{"code":500,"message":"boom"}`)))
	}))
	defer srv.Close()

	api := NewScheduleAPI(&config.Config{DashboardAPIBase: srv.URL, DashboardAPIToken: "tok"})
	if _, err := api.Create(context.Background(), "cid-1", "2", "s1", "n", "message", "0 9 * * 1", nil, "x"); err == nil || !strings.Contains(err.Error(), "500") {
		t.Errorf("create err = %v, want the server error", err)
	}
	if _, err := api.List(context.Background(), "cid-1"); err == nil || !strings.Contains(err.Error(), "500") {
		t.Errorf("list err = %v, want the server error", err)
	}
	if err := api.Cancel(context.Background(), "cid-1", 3); err == nil || !strings.Contains(err.Error(), "500") {
		t.Errorf("cancel err = %v, want the server error", err)
	}
}
