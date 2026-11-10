# dingtalk-agent — DingTalk stream-mode AI agent (GoAgent fork)
FROM golang:1.25-alpine AS build
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN CGO_ENABLED=0 go build -ldflags="-s -w" -o /agent ./cmd/agent

FROM alpine:3.20
RUN apk add --no-cache ca-certificates tzdata \
    && mkdir -p /data && chown 65534:65534 /data
COPY --from=build /agent /agent
ENV DINGTALK_DATA_DIR=/data
USER 65534
ENTRYPOINT ["/agent"]
