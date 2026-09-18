"""Différence entre deux états d'installation (etat_installation.py) : chaque écart sur une ligne, chemin complet.
Code de sortie 1 s'il y a au moins un écart. Usage : python3 comparer_etats.py avant.json apres.json
"""
import json
import sys


def ecarts(a, b, chemin=""):
    if isinstance(a, dict) and isinstance(b, dict):
        for cle in sorted(set(a) | set(b)):
            if cle not in a:
                yield f"{chemin}/{cle} : absent avant, présent après : {json.dumps(b[cle], ensure_ascii=False)[:200]}"
            elif cle not in b:
                yield f"{chemin}/{cle} : présent avant, absent après : {json.dumps(a[cle], ensure_ascii=False)[:200]}"
            else:
                yield from ecarts(a[cle], b[cle], f"{chemin}/{cle}")
    elif isinstance(a, list) and isinstance(b, list):
        if a != b:
            if len(a) != len(b):
                yield f"{chemin} : {len(a)} élément(s) avant, {len(b)} après"
            for i, (x, y) in enumerate(zip(a, b)):
                if x != y:
                    yield from ecarts(x, y, f"{chemin}[{i}]")
            for x in a[len(b):]:
                yield f"{chemin} : en moins après : {json.dumps(x, ensure_ascii=False)[:200]}"
            for y in b[len(a):]:
                yield f"{chemin} : en plus après : {json.dumps(y, ensure_ascii=False)[:200]}"
    elif a != b:
        yield f"{chemin} : {json.dumps(a, ensure_ascii=False)[:120]} → {json.dumps(b, ensure_ascii=False)[:120]}"


def main():
    avant = json.load(open(sys.argv[1], encoding="utf-8"))
    apres = json.load(open(sys.argv[2], encoding="utf-8"))
    liste = list(ecarts(avant, apres))
    for ligne in liste:
        print(ligne)
    print(f"{len(liste)} écart(s)")
    return 1 if liste else 0


if __name__ == "__main__":
    sys.exit(main())
