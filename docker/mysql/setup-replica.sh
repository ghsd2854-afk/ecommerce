#!/bin/bash
# Requirement 3 (layer 4): connects the read replica (db-replica) to the primary (db).
#
# Runs automatically on every "docker compose up" (service db-replica-setup) and is
# safe to run again:
#   - replica already connected -> only makes sure replication is running
#   - first time (or FORCE=1)   -> copies the current data of the primary, then turns on
#                                  replication from exactly that point (GTID auto-position)
#
# Works with an EXISTING primary that already has data (the database of Requirements 1-2).
set -euo pipefail

PRIMARY_HOST="${PRIMARY_HOST:-db}"
PRIMARY_PORT="${PRIMARY_PORT:-3306}"
REPLICA_HOST="${REPLICA_HOST:-db-replica}"
REPLICA_PORT="${REPLICA_PORT:-3306}"
DATABASE="${DATABASE:-shop}"
REPL_USER="${REPL_USER:-repl}"
REPL_PASSWORD="${REPL_PASSWORD:-replsecret}"
export MYSQL_PWD="${MYSQL_ROOT_PASSWORD:-root}"   # avoids the password on the command line

primary() { mysql -h "$PRIMARY_HOST" -P "$PRIMARY_PORT" -uroot "$@"; }
replica() { mysql -h "$REPLICA_HOST" -P "$REPLICA_PORT" -uroot "$@"; }

wait_for() {
  local name=$1; shift
  for _ in $(seq 1 90); do
    if "$@" -e "select 1" >/dev/null 2>&1; then return 0; fi
    sleep 2
  done
  echo "[replica-setup] $name is not reachable" >&2
  exit 1
}

replica_running() {
  local status
  status=$(replica -e "SHOW REPLICA STATUS\G" 2>/dev/null || true)
  echo "$status" | grep -q "Replica_IO_Running: Yes" && echo "$status" | grep -q "Replica_SQL_Running: Yes"
}

wait_for primary primary
wait_for replica replica

configured=$(replica -N -e "select count(*) from performance_schema.replication_connection_configuration")

if [ "$configured" != "0" ] && [ "${FORCE:-0}" != "1" ]; then
  echo "[replica-setup] replica already configured, making sure it is running"
  replica -e "START REPLICA" 2>/dev/null || true
else
  echo "[replica-setup] first-time setup: copying '$DATABASE' from the primary to the replica"

  # Replication account on the primary (not written to the binary log: the replica
  # must not copy it, it only needs to log in with it).
  primary -e "SET SQL_LOG_BIN=0;
    CREATE USER IF NOT EXISTS '$REPL_USER'@'%' IDENTIFIED BY '$REPL_PASSWORD';
    GRANT REPLICATION SLAVE ON *.* TO '$REPL_USER'@'%';
    CREATE DATABASE IF NOT EXISTS \`$DATABASE\`;"

  # Clean replica state, then a consistent snapshot of the primary.
  # --single-transaction: consistent copy without locking the primary
  # --set-gtid-purged=ON: the copy carries the primary's GTID position, so the replica
  #                       knows exactly from which transaction to continue.
  replica -e "STOP REPLICA; RESET REPLICA ALL; RESET MASTER;" 2>/dev/null || true
  mysqldump -h "$PRIMARY_HOST" -P "$PRIMARY_PORT" -uroot \
    --databases "$DATABASE" --single-transaction --set-gtid-purged=ON \
    --routines --triggers --events --no-tablespaces \
    | replica

  replica -e "CHANGE REPLICATION SOURCE TO
      SOURCE_HOST='$PRIMARY_HOST', SOURCE_PORT=$PRIMARY_PORT,
      SOURCE_USER='$REPL_USER', SOURCE_PASSWORD='$REPL_PASSWORD',
      SOURCE_AUTO_POSITION=1, GET_SOURCE_PUBLIC_KEY=1;
    START REPLICA;"
fi

# Wait until both replication threads run. After an unclean stop (e.g. "wsl --shutdown")
# MySQL first does crash recovery, so on a slow laptop this can take a while.
for i in $(seq 1 120); do
  if replica_running; then
    echo "[replica-setup] OK: replication is running ($PRIMARY_HOST -> $REPLICA_HOST)"
    exit 0
  fi
  if [ $((i % 15)) -eq 0 ]; then
    echo "[replica-setup] still waiting for replication to run ($i s)..."
    replica -e "START REPLICA" 2>/dev/null || true
  fi
  sleep 1
done

echo "[replica-setup] replication is NOT running:" >&2
replica -e "SHOW REPLICA STATUS\G" | grep -E "Running|Last_.*Error|Seconds_Behind" >&2 || true
echo "[replica-setup] To rebuild the replica from a fresh copy of the primary run:  .\\demo.cmd resync" >&2
exit 1
