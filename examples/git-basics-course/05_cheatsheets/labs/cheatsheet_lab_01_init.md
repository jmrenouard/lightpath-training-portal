# 🧪 Fiche Mémo Atelier 01 : Démarrage & .gitignore

> **Référence :** Atelier 01 (Les Bases de Git — Licence MIT)
> **Formateur :** Jean-Marie Renouard — LightPath SARL

## Séquence Idempotente d'Initialisation

```bash
# Initialize repo with main branch and identity
git init -b main
git config user.name "Alice Martin"
git config user.email "alice.martin@lightpath.fr"

# Create .gitignore first to exclude secrets and logs
printf ".env\n*.log\n__pycache__/\n" > .gitignore
git add .gitignore
git commit -m "chore: add gitignore rules"
```

## Contrôles Rapides de Conformité

* `git ls-files` : vérifie qu'aucun fichier `.env` ou `.log` n'est indexé.
* `git status --porcelain` : doit retourner une sortie vide une fois le travail validé.
* `git rev-list --count HEAD` : compte le nombre total de commits accessibles depuis `HEAD`.
