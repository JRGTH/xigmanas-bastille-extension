#!/bin/sh
#
# bastille_manager_release_job.sh
#
# Runs a Bastille base release bootstrap/destroy detached from the web request.
# Started by BastilleReleaseJob (PHP); the GUI only polls the files written here.
#
# Usage:
#   bastille_manager_release_job.sh JOB_DIR MODE RELEASE BASTILLE_CONF ARCHIVES RESTORE_ARCHIVES RESUME
#
#   JOB_DIR           directory where job.pid, job.log and job.exit are written
#   MODE              bootstrap | destroy
#   RELEASE           e.g. 14.4-RELEASE (already validated by the caller)
#   BASTILLE_CONF     path of bastille.conf ("" to leave it untouched)
#   ARCHIVES          archives to bootstrap, e.g. "base lib32"
#   RESTORE_ARCHIVES  value of bastille_bootstrap_archives to put back afterwards
#   RESUME            1 = pre-download archives resumably, 0 = plain bastille bootstrap
#
# Why the pre-download ("prefetch") exists, i.e. assumptions about Bastille:
#   - bastille's own fetch cannot resume and leaves a partial file in the cache, which then
#     fails the checksum on every retry;
#   - a failed first bootstrap makes bastille rm -rf its cache/<release> directory, so the
#     download is staged in <cache>/.resume/<release> and linked into cache/<release>;
#   - bastille creates cache/<release> as a ZFS dataset only when the directory does not
#     exist, so prefetch must create it the same way (see prepare_cache_dir).
# If any of this stops being true, run with RESUME=0 (RELEASE_RESUME_DOWNLOADS="NO" in the
# extension config) to fall back to a plain "bastille bootstrap".
#
# Binaries and timings can be overridden through the environment (used by the tests).

JOB_DIR=$1
MODE=$2
RELEASE=$3
CONF=$4
ARCHIVES=$5
RESTORE_ARCHIVES=$6
RESUME=${7:-1}

BASTILLE_BIN=${BASTILLE_BIN:-/usr/local/bin/bastille}
FETCH_BIN=${FETCH_BIN:-/usr/bin/fetch}
SYSRC_BIN=${SYSRC_BIN:-/usr/sbin/sysrc}
ZFS_BIN=${ZFS_BIN:-/sbin/zfs}
ARCH_M=${ARCH_M:-$(uname -m)}
ARCH_P=${ARCH_P:-$(uname -p)}
MAX_ATTEMPTS=${MAX_ATTEMPTS:-10}
RETRY_DELAY=${RETRY_DELAY:-10}
FETCH_TIMEOUT=${FETCH_TIMEOUT:-30}
PROGRESS_INTERVAL=${PROGRESS_INTERVAL:-1}

PID_FILE="$JOB_DIR/job.pid"
LOG_FILE="$JOB_DIR/job.log"
EXIT_FILE="$JOB_DIR/job.exit"

echo $$ > "$PID_FILE"
exec < /dev/null >> "$LOG_FILE" 2>&1

# Size in bytes of a regular file (0 when missing). Portable: BSD and GNU.
file_size() {
    if [ -f "$1" ]; then
        wc -c < "$1" | tr -d ' '
    else
        echo 0
    fi
}

# Create <cache>/<release> the way bastille does: a ZFS dataset when ZFS is enabled,
# a plain directory otherwise. A plain directory on a ZFS setup would stop bastille from
# creating the dataset and make "bastille destroy" fail later.
prepare_cache_dir() {
    [ -d "$1" ] && return 0
    case "$bastille_zfs_enable" in
        [Yy][Ee][Ss]|[Tt][Rr][Uu][Ee]|[Oo][Nn]|1) zfs_on=1 ;;
        *) zfs_on=0 ;;
    esac
    if [ "$zfs_on" -eq 1 ] && [ -n "$bastille_zfs_zpool" ]; then
        # shellcheck disable=SC2086  # bastille_zfs_options is a list of options
        "$ZFS_BIN" create $bastille_zfs_options -o mountpoint="$1" \
            "$bastille_zfs_zpool/$bastille_zfs_prefix/cache/$RELEASE"
    else
        mkdir -p "$1"
    fi
}

# Download one archive into the staging dir with fetch -r (restart at the interrupted
# byte). fetch only draws its progress bar on a tty, so progress is reported from the
# file size instead.
download_archive() {
    _url=$1
    _file=$2
    _name=$3
    _remote=$4

    "$FETCH_BIN" -q -r -T "$FETCH_TIMEOUT" -o "$_file" "$_url" &
    _pid=$!
    while kill -0 "$_pid" 2>/dev/null; do
        _cur=$(file_size "$_file")
        printf '\r%s: %d / %d MB (%d%%)    ' "$_name" \
            $((_cur / 1048576)) $((_remote / 1048576)) $((_cur * 100 / _remote))
        sleep "$PROGRESS_INTERVAL"
    done
    wait "$_pid"
    _rc=$?
    if [ "$_rc" -ne 0 ]; then
        printf '\n'
        return 1
    fi
    printf '\r%s: %d / %d MB (100%%)    \n' "$_name" $((_remote / 1048576)) $((_remote / 1048576))
}

# Pre-download every archive. Runs in a subshell so sourcing bastille.conf does not leak.
prefetch() (
    [ -n "$CONF" ] && . "$CONF" 2>/dev/null
    # First ever bootstrap: no cache yet, let bastille create its datasets itself.
    [ -d "$bastille_cachedir" ] || return 0

    url="${bastille_url_freebsd}${ARCH_M}/${ARCH_P}/${RELEASE}"
    real="$bastille_cachedir/$RELEASE"
    stage="$bastille_cachedir/.resume/$RELEASE"
    mkdir -p "$stage" || return 1
    prepare_cache_dir "$real" || return 1

    for archive in $ARCHIVES; do
        file="$stage/$archive.txz"
        remote=$("$FETCH_BIN" -T "$FETCH_TIMEOUT" -s "$url/$archive.txz" 2>/dev/null)
        case "$remote" in
            ''|*[!0-9]*)
                # Size unknown (e.g. EOL release on another mirror): let bastille handle it.
                echo "[Size of $archive.txz unknown at $url, leaving it to bastille (no resume)]"
                continue ;;
        esac
        echo "$remote" > "$stage/$archive.size"

        have=$(file_size "$file")
        [ "$have" -eq "$remote" ] && continue
        if [ "$have" -gt "$remote" ]; then
            rm -f "$file"
            have=0
        fi
        [ "$have" -gt 0 ] && echo "[Resuming $archive.txz at $have of $remote bytes]"
        download_archive "$url/$archive.txz" "$file" "$archive.txz" "$remote" || return 1
    done

    for archive in $ARCHIVES; do
        [ -f "$stage/$archive.txz" ] || continue
        ln -f "$stage/$archive.txz" "$real/$archive.txz" 2>/dev/null \
            || cp -f "$stage/$archive.txz" "$real/$archive.txz"
    done
    return 0
)

# Remove the staging dir once the release is installed.
cleanup_stage() (
    [ -n "$CONF" ] && . "$CONF" 2>/dev/null
    [ -n "$bastille_cachedir" ] || return 0
    rm -rf "${bastille_cachedir:?}/.resume/$RELEASE"
    rmdir "$bastille_cachedir/.resume" 2>/dev/null
    return 0
)

set_archives() {
    [ -n "$CONF" ] || return 0
    # sysrc adds the quotes itself: never embed them in the value.
    "$SYSRC_BIN" -f "$CONF" "bastille_bootstrap_archives=$1" > /dev/null
}

run_bootstrap() {
    # Override default distfiles once.
    set_archives "$ARCHIVES"

    attempt=1
    bastille_failures=0
    while :; do
        if [ "$RESUME" != 1 ] || prefetch; then
            echo "Y" | "$BASTILLE_BIN" bootstrap "$RELEASE"
            rc=$?
            # A failure of bastille itself (not of the transfer) is rarely transient.
            [ "$rc" -ne 0 ] && bastille_failures=$((bastille_failures + 1))
            [ "$bastille_failures" -ge 2 ] && break
        else
            rc=1
        fi
        if [ "$rc" -eq 0 ]; then
            cleanup_stage
            break
        fi
        [ "$attempt" -ge "$MAX_ATTEMPTS" ] && break
        attempt=$((attempt + 1))
        echo
        echo "[Attempt failed. Retrying $attempt/$MAX_ATTEMPTS in ${RETRY_DELAY}s...]"
        sleep "$RETRY_DELAY"
    done

    # Set back default distfiles.
    set_archives "$RESTORE_ARCHIVES"
    return "$rc"
}

run_destroy() {
    # Already confirmed in the browser and checked for child jails by the caller.
    echo "y" | "$BASTILLE_BIN" destroy "$RELEASE"
}

case "$MODE" in
    bootstrap) run_bootstrap ;;
    destroy)   run_destroy ;;
    *)         echo "Unknown mode: $MODE"; false ;;
esac
rc=$?

echo
if [ "$rc" -eq 0 ]; then
    echo "[Completed]"
else
    echo "[Failed (exit code $rc)]"
fi
echo "$rc" > "$EXIT_FILE"
exit "$rc"
