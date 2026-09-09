#!/bin/bash
# Runs on an app instance via SSM Run Command (see .github/workflows/db-init.yml).
# Idempotent: skips the import if shuttle_bus_db.users already exists, so
# accidentally re-running the workflow later is a safe no-op instead of
# crashing on duplicate-key errors from schema.sql's seed INSERTs.
set -euo pipefail

AWS_REGION="${AWS_REGION:-us-east-1}"
SECRET_ID="assignment-db-credentials"
SCHEMA_S3_URI="$1"
BUCKET_NAME="${2:-}"
CDN_DOMAIN="${3:-}"

SECRET_JSON=$(aws secretsmanager get-secret-value \
  --secret-id "$SECRET_ID" --region "$AWS_REGION" --query SecretString --output text)

DB_HOST=$(echo "$SECRET_JSON" | jq -r .host)
DB_USER=$(echo "$SECRET_JSON" | jq -r .username)
DB_PASS=$(echo "$SECRET_JSON" | jq -r .password)

# Determine the preferred image prefix: CloudFront CDN (preferred) or S3 direct
IMAGE_PREFIX=""
if [ -n "$CDN_DOMAIN" ]; then
  IMAGE_PREFIX="https://${CDN_DOMAIN}/uploads/"
elif [ -n "$BUCKET_NAME" ]; then
  IMAGE_PREFIX="https://${BUCKET_NAME}.s3.${AWS_REGION}.amazonaws.com/uploads/"
fi

ALREADY_SEEDED=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -N -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='shuttle_bus_db' AND table_name='users'")

if [ "$ALREADY_SEEDED" -gt 0 ]; then
  echo "shuttle_bus_db.users already exists - database already seeded, skipping import."
  if [ -n "$IMAGE_PREFIX" ]; then
    # Fix/upgrade image paths even if already seeded so existing rows point to CDN
    mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -D "shuttle_bus_db" -e \
      "UPDATE routes SET image_url = CONCAT('${IMAGE_PREFIX}', SUBSTRING_INDEX(image_url, '/', -1)) WHERE image_url IS NOT NULL AND image_url != '';"
    echo "Updated sample route image URLs to: ${IMAGE_PREFIX}"
  fi

  # Ensure performance indexes exist even on pre-existing databases
  INDEX_EXISTS=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -D "shuttle_bus_db" -N -e \
    "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema='shuttle_bus_db' AND table_name='tickets' AND index_name='idx_tickets_travel_trip'" 2>/dev/null || echo "0")
  if [ "$INDEX_EXISTS" -eq 0 ]; then
    echo "Adding performance indexes to tickets table..."
    mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -D "shuttle_bus_db" -e \
      "ALTER TABLE tickets ADD INDEX idx_tickets_travel_trip (travel_date, trip_id, seat_quantity), ADD INDEX idx_tickets_user_trip_date (user_id, trip_id, travel_date);" 2>/dev/null || true
    echo "Performance indexes added."
  fi
  exit 0
fi

aws s3 cp "$SCHEMA_S3_URI" /tmp/schema.sql --region "$AWS_REGION"
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" < /tmp/schema.sql

if [ -n "$IMAGE_PREFIX" ]; then
  # Rewrite image paths to CDN or S3 URLs for the sample routes
  mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -D "shuttle_bus_db" -e \
    "UPDATE routes SET image_url = CONCAT('${IMAGE_PREFIX}', SUBSTRING_INDEX(image_url, '/', -1)) WHERE image_url IS NOT NULL AND image_url != '';"
  echo "Updated sample route image URLs to: ${IMAGE_PREFIX}"
fi

echo "Database seeded successfully."
