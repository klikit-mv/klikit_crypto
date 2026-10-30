#!/usr/bin/env python3
"""
Mining agent: reads statistics from a CGMiner-compatible API (cgminer, bmminer, BOSminer,
most ASIC firmwares; TCP port 4028) and reports them to the trading platform.

  MINING_AGENT_TOKEN=mdt_...  PLATFORM_URL=https://platform.example.com  python3 agent.py

Optional: MINER_HOST (default 127.0.0.1), MINER_PORT (4028), INTERVAL seconds (60),
WORKER_PREFIX (default: hostname). Standard library only.
"""
import json
import os
import socket
import sys
import time
import urllib.request

TOKEN = os.environ.get("MINING_AGENT_TOKEN", "")
URL = os.environ.get("PLATFORM_URL", "").rstrip("/") + "/api/v1/mining/agent/stats"
HOST = os.environ.get("MINER_HOST", "127.0.0.1")
PORT = int(os.environ.get("MINER_PORT", "4028"))
INTERVAL = int(os.environ.get("INTERVAL", "60"))
PREFIX = os.environ.get("WORKER_PREFIX", socket.gethostname())


def miner_command(command):
    with socket.create_connection((HOST, PORT), timeout=5) as sock:
        sock.sendall(json.dumps({"command": command}).encode())
        data = b""
        while chunk := sock.recv(65536):
            data += chunk
    # Many firmwares terminate the reply with a NUL byte.
    return json.loads(data.replace(b"\x00", b"").decode(errors="replace"))


def collect():
    devs = miner_command("devs").get("DEVS", [])
    workers = []
    for dev in devs:
        mhs = dev.get("MHS 5s") or dev.get("MHS av") or 0
        ghs = dev.get("GHS 5s") or dev.get("GHS av")
        hashrate = float(ghs) * 1e9 if ghs is not None else float(mhs) * 1e6
        workers.append({
            "name": f"{PREFIX}-{dev.get('Name', 'dev')}{dev.get('ID', dev.get('ASC', len(workers)))}",
            "hashrate": round(hashrate, 2),
            "temperature": dev.get("Temperature"),
            "accepted": int(dev.get("Accepted", 0)),
            "rejected": int(dev.get("Rejected", 0)),
        })
    return workers


def report(workers):
    request = urllib.request.Request(
        URL,
        data=json.dumps({"workers": workers}).encode(),
        headers={"Authorization": f"Bearer {TOKEN}", "Content-Type": "application/json", "Accept": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(request, timeout=10) as response:
        return json.loads(response.read())


def main():
    if not TOKEN or not URL.startswith("http"):
        sys.exit("Set MINING_AGENT_TOKEN and PLATFORM_URL.")
    while True:
        try:
            workers = collect()
            if workers:
                print(time.strftime("%H:%M:%S"), "reported", report(workers))
        except Exception as exc:  # keep running through miner restarts and network blips
            print(time.strftime("%H:%M:%S"), "error:", exc, file=sys.stderr)
        time.sleep(INTERVAL)


if __name__ == "__main__":
    main()
