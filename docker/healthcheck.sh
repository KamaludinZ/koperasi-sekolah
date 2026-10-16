#!/bin/sh
# Pemeriksaan kesehatan Docker: peran web memeriksa /up (nginx → php-fpm → basis data);
# peran worker/scheduler memastikan prosesnya hidup di supervisord.
case "${CONTAINER_ROLE:-all}" in
    all|web) exec curl -fsS -o /dev/null --max-time 4 http://127.0.0.1:8080/up ;;
    *) supervisorctl -c /etc/supervisord.conf status | grep -q RUNNING ;;
esac
