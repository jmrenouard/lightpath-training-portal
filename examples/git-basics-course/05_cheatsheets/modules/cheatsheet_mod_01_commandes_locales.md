# 📋 Fiche Mémo Module 01 : Commandes Locales Git

> **Référence :** Module 01 (Les Bases de Git — Licence MIT)
> **Formateur :** Jean-Marie Renouard — LightPath SARL

## Cycle de Vie des Trois Zones

| Commande | Zone Source → Cible | Usage Principal |
| :--- | :--- | :--- |
| `git init -b main` | Nouveau dossier → `.git` | Initialise un dépôt local sur la branche `main` |
| `git status -sb` | Working Tree / Index | Affiche la branche active et les fichiers modifiés/indexés |
| `git add <fichier>` | Working Tree → Index | Prépare un fichier ciblé pour le prochain commit |
| `git diff --staged` | Index vs `HEAD` | Relit le patch exact qui sera enregistré par `git commit` |
| `git commit -m "..."` | Index → Dépôt `.git` | Enregistre un instantané atomique dans l'historique |
| `git restore --staged <f>` | Index → Working Tree | Retire un fichier de l'Index sans perdre son contenu |

## Commandes Express de Vérification

```bash
# Inspect author configuration and compact commit history
git config --list --show-origin
git log --oneline --graph --decorate -n 10

# Untrack a file added by mistake while keeping it on disk
git rm --cached .env
```
