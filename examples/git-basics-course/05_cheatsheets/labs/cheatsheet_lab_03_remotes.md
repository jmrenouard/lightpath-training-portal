# 🧪 Fiche Mémo Atelier 03 : Synchronisation & Pull Request

> **Référence :** Atelier 03 (Les Bases de Git — Licence MIT)
> **Formateur :** Jean-Marie Renouard — LightPath SARL

## Dépôt Serveur Nu (`--bare`) & Synchronisation Multi-Postes

```bash
# Create local bare repository simulating a central server
git init --bare -b main /tmp/lp-git-lab03/origin-repo.git

# Inspect remote delta before pulling
git fetch origin
git log --oneline main..origin/main

# Recover cleanly from a rejected non-fast-forward push
git pull --rebase origin main
git push origin main
```

## Nettoyage Post-Pull Request

* Supprimer la branche distante fusionnée : `git push origin --delete feat/healthcheck-endpoint`.
* Nettoyer le clone local et purger les références distantes obsolètes : `git pull --prune origin main && git branch -d feat/healthcheck-endpoint`.
