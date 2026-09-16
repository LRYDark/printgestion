"""SIMULATION DE TEST — faux serveur SMTP local pour le harnais de Print Gestion.

N'a rien à faire sur un serveur de production. N'écoute que sur 127.0.0.1, accepte tout message et l'écrit dans un
fichier .eml du dossier donné ; rien n'est jamais relayé.
Usage : python3 smtp_factice.py <dossier des mails> [port]
"""
import os
import socketserver
import sys
import time

DOSSIER = sys.argv[1]
PORT = int(sys.argv[2]) if len(sys.argv) > 2 else 2525


class Traitement(socketserver.StreamRequestHandler):
    def handle(self):
        self.wfile.write(b"220 localhost ESMTP simulation\r\n")
        donnees, lignes, destinataires, expediteur = False, [], [], ""
        while True:
            ligne = self.rfile.readline()
            if not ligne:
                break
            if donnees:
                if ligne in (b".\r\n", b".\n"):
                    chemin = os.path.join(DOSSIER, f"mail-{time.time():.6f}.eml")
                    with open(chemin, "wb") as fichier:
                        fichier.write(f"X-Env-From: {expediteur}\r\nX-Env-To: {','.join(destinataires)}\r\n".encode() + b"".join(lignes))
                    donnees, lignes, destinataires = False, [], []
                    self.wfile.write(b"250 OK\r\n")
                else:
                    lignes.append(ligne[1:] if ligne.startswith(b"..") else ligne)
                continue
            commande = ligne.strip().upper()
            if commande.startswith(b"EHLO"):
                self.wfile.write(b"250-localhost\r\n250-8BITMIME\r\n250 SIZE 20971520\r\n")
            elif commande.startswith(b"MAIL FROM"):
                expediteur = ligne.strip()[10:].decode(errors="replace")
                self.wfile.write(b"250 OK\r\n")
            elif commande.startswith(b"RCPT TO"):
                destinataires.append(ligne.strip()[8:].decode(errors="replace"))
                self.wfile.write(b"250 OK\r\n")
            elif commande == b"DATA":
                donnees = True
                self.wfile.write(b"354 End data with <CR><LF>.<CR><LF>\r\n")
            elif commande == b"QUIT":
                self.wfile.write(b"221 Bye\r\n")
                break
            else:
                self.wfile.write(b"250 OK\r\n")


class Serveur(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


if __name__ == "__main__":
    os.makedirs(DOSSIER, exist_ok=True)
    Serveur(("127.0.0.1", PORT), Traitement).serve_forever()
