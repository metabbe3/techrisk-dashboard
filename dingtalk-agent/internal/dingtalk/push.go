package dingtalk

import (
	"context"
	"encoding/json"
	"net/http"

	"techrisk-dashboard/dingtalk-agent/internal/config"
)

// maxDMRecipients mirrors the documented batchSend ceiling (20 per call).
const maxDMRecipients = 20

// SendMarkdownDM proactively pushes a robot DM (markdown) to staffIds via
// POST /v1.0/robot/oToMessages/batchSend. Requires the app permission
// qyapi_robot_sendmsg ("send messages with a bot in your organization") and
// recipients who have opened a chat with the robot at least once.
func SendMarkdownDM(ctx context.Context, cfg *config.Config, staffIDs []string, title, markdown string) error {
	if len(staffIDs) > maxDMRecipients {
		staffIDs = staffIDs[:maxDMRecipients]
	}
	msgParam, err := json.Marshal(map[string]string{"title": title, "text": markdown})
	if err != nil {
		return err
	}
	client := newOpenAPIClient(cfg)
	return client.do(ctx, http.MethodPost, "/v1.0/robot/oToMessages/batchSend", map[string]any{
		"robotCode": cfg.DingTalkClientID,
		"userIds":   staffIDs,
		"msgKey":    "sampleMarkdown",
		"msgParam":  string(msgParam),
	})
}
