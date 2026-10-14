#!/bin/sh
set -eu

mode="${TLS_TERMINATION:-nginx}"
case "$mode" in
    edge|nginx) ;;
    *)
        echo "TLS_TERMINATION must be edge or nginx." >&2
        exit 1
        ;;
esac

if [ "$mode" = "nginx" ]; then
    if [ ! -f /etc/nginx/certs/fullchain.pem ] || [ ! -f /etc/nginx/certs/privkey.pem ]; then
        echo "TLS_TERMINATION=nginx requires certificate files mounted at /etc/nginx/certs." >&2
        exit 1
    fi
    cp /etc/nginx/available/terminate.conf /etc/nginx/conf.d/default.conf
else
    cp /etc/nginx/available/edge.conf /etc/nginx/conf.d/default.conf
fi
