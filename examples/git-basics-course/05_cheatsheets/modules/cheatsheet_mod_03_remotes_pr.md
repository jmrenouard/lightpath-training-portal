# 📋 Fiche Mémo Module 03 : Dépôts Distants & Pull Requests

> **Référence :** Module 03 (Les Bases de Git — Licence MIT)
> **Formateur :** Jean-Marie Renouard — LightPath SARL

## Commandes de Synchronisation Distante

| Commande | Effet Réseau & Local | Bon Usage |
| :--- | :--- | :--- |
| `git clone <url>` | Clone le dépôt et configure `origin` | Démarrage sur un projet existant |
| `git remote -v` | Liste les dépôts distants (`fetch` / `push`) | Vérifie les URL SSH ou HTTPS actives |
| `git fetch origin` | Télécharge dans `origin/*` sans modifier `HEAD` | Inspecter avant d'intégrer (`git log HEAD..origin/main`) |
| `git pull --ff-only` | Télécharge et avance uniquement en Fast-Forward | Évite les commits de fusion involontaires |
| `git pull --rebase` | Télécharge et réapplique les commits locaux | Maintient un historique linéaire propre |
| `git push -u origin feat/x` | Publie la branche et lie le suivi amont | Premier envoi d'une branche de Pull Request |
| `git push origin --delete feat/x` | Supprime la branche distante | Nettoyage après fusion d'une PR |

## Workflow Standard Pull Request

```bash
# 1. Create feature branch from updated main
git switch main && git pull --ff-only origin main
git switch -c feat/my-change

# 2. Commit tested work and publish PR branch
git push -u origin feat/my-change
```
