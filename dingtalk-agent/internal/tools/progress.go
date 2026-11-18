package tools

import "context"

// B1 (audit round 5): a ctx-keyed progress hook the AI card subscribes to —
// a multi-tool turn shows "checking X…" while tools run instead of a black
// hole. Kept in tools so the loop's exec call site needs no new dependency.
type toolProgressKey struct{}

// WithToolProgress attaches a per-tool-start callback to ctx.
func WithToolProgress(ctx context.Context, fn func(toolName string)) context.Context {
	return context.WithValue(ctx, toolProgressKey{}, fn)
}

// NotifyToolProgress reports one tool's start; a missing hook is a no-op
// (plain turns carry none).
func NotifyToolProgress(ctx context.Context, toolName string) {
	if fn, ok := ctx.Value(toolProgressKey{}).(func(string)); ok && fn != nil {
		fn(toolName)
	}
}
