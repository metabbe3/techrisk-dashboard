#!/bin/bash
set -e

# Set the backup directory
BACKUP_DIR="/backups"
# Set the filename with a timestamp
FILENAME="$BACKUP_DIR/backup-$(date +%Y-%m-%d-%H-%M-%S).sql"

# Dump the database
mysqldump -h db -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" > "$FILENAME"

# Optional: Gzip the backup
gzip "$FILENAME"

# Optional: Remove backups older than 7 days
find "$BACKUP_DIR" -type f -name "*.sql.gz" -mtime +7 -delete
