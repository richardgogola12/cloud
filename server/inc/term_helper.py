#!/usr/bin/env python3
"""Pomocník webového terminálu (Cloud – Administrácia → Terminál).

Spustí príkaz (ssh) v pseudo-terminále a prepája ho so súbormi v adresári relácie:
  in      – vstup z prehliadača (PHP ho dopĺňa na koniec, čítame od posledného miesta)
  out     – výstup terminálu (PHP ho číta od posledného miesta)
  resize  – "riadky stĺpce" pri zmene veľkosti okna
  alive   – PHP ho obnovuje pri každom čítaní; keď prehliadač zmizne, relácia sa ukončí
  close   – žiadosť o ukončenie
  ended   – vytvorí sa po skončení
Použitie: term_helper.py <adresár> <riadky> <stĺpce> <príkaz> [argumenty…]
"""
import os, sys, pty, select, time, fcntl, termios, struct, signal

d, rows, cols = sys.argv[1], int(sys.argv[2]), int(sys.argv[3])
cmd = sys.argv[4:]
IDLE = 30 * 60       # bez písania dlhšie ako 30 min → koniec
GONE = 90            # prehliadač sa neozval 90 s → koniec
p = lambda n: os.path.join(d, n)

pid, fd = pty.fork()
if pid == 0:
    os.environ['TERM'] = 'xterm-256color'
    os.environ['COLORTERM'] = 'truecolor'
    os.environ.setdefault('LANG', 'C.UTF-8')
    os.environ['HOME'] = os.path.dirname(d.rstrip('/'))
    try:
        os.execvp(cmd[0], cmd)
    finally:
        os._exit(127)

def setsize(r, c):
    try:
        fcntl.ioctl(fd, termios.TIOCSWINSZ, struct.pack('HHHH', max(2, r), max(10, c), 0, 0))
    except OSError:
        pass

setsize(rows, cols)
with open(p('pid'), 'w') as f:
    f.write(str(os.getpid()))
out = open(p('out'), 'ab', buffering=0)
open(p('in'), 'ab').close()
inp = open(p('in'), 'rb')
last_input = time.time()

try:
    while True:
        r, _, _ = select.select([fd], [], [], 0.03)
        if r:
            try:
                data = os.read(fd, 65536)
            except OSError:
                break                      # proces v termináli skončil
            if not data:
                break
            out.write(data)
        data = inp.read(65536)
        if data:
            last_input = time.time()
            view = memoryview(data)
            while view:
                try:
                    n = os.write(fd, view)
                    view = view[n:]
                except BlockingIOError:
                    time.sleep(0.01)
        if os.path.exists(p('resize')):
            try:
                with open(p('resize')) as f:
                    rr, cc = (int(x) for x in f.read().split()[:2])
                os.unlink(p('resize'))
                setsize(rr, cc)
            except (OSError, ValueError):
                pass
        now = time.time()
        if os.path.exists(p('close')) or now - last_input > IDLE:
            break
        try:
            if now - os.path.getmtime(p('alive')) > GONE:
                break
        except OSError:
            pass
finally:
    try:
        os.kill(pid, signal.SIGHUP)
        time.sleep(0.3)
        os.kill(pid, signal.SIGKILL)
    except OSError:
        pass
    try:
        os.waitpid(pid, 0)
    except OSError:
        pass
    open(p('ended'), 'w').close()
