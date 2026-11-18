# 🧪 Atelier 03 : Synchronisation Remote & Workflow Pull Request

> **Atelier :** 03 — Dépôts Distants (`--bare`), `fetch`, `pull --rebase`, `push -u` & Pull Request
> **Durée :** 1h15
> **Compétence Visée (Bloom C3) :** Synchroniser un dépôt local avec un serveur distant et intégrer une branche de fonctionnalité via Pull Request
> **Environnement :** Terminal Git local (Linux, macOS ou Git Bash)

---

## 1. Prérequis, Mise en Situation & Amorçage Idempotent

### Scénario Métier
Deux développeurs, Alice (`dev-alice`) et Bob (`dev-bob`), collaborent sur un serveur Git centralisé simulé localement par un dépôt nu (`/tmp/lp-git-lab03/origin-repo.git`). Bob pousse un correctif sur `main` pendant qu'Alice développe en parallèle. Alice doit d'abord récupérer les nouveautés distantes avec `git fetch` et `git pull --rebase`, puis ouvrir une branche dédiée `feat/healthcheck-endpoint`, la publier avec `git push -u origin`, simuler la revue/fusion de Pull Request sur `main`, et enfin resynchroniser les dépôts.

### Script d'Amorçage Idempotent
Exécutez ce bloc Bash pour créer le serveur distant `--bare` et les deux clones locaux (`dev-alice` et `dev-bob`) :

```bash
# Idempotent multi-developer bare remote setup for Lab 03
rm -rf /tmp/lp-git-lab03
mkdir -p /tmp/lp-git-lab03

git init --bare -b main /tmp/lp-git-lab03/origin-repo.git

git clone /tmp/lp-git-lab03/origin-repo.git /tmp/lp-git-lab03/dev-alice
cd /tmp/lp-git-lab03/dev-alice
git config user.name "Alice Martin"
git config user.email "alice.martin@lightpath.fr"

cat <<'EOF' > app.py
APP_VERSION = "1.0.0"
EOF
git add app.py
git commit -m "chore: bootstrap application skeleton"
git push -u origin main

git clone /tmp/lp-git-lab03/origin-repo.git /tmp/lp-git-lab03/dev-bob
cd /tmp/lp-git-lab03/dev-bob
git config user.name "Bob Dupont"
git config user.email "bob.dupont@lightpath.fr"
```

---

## 2. Travail Pas-à-Pas

### Étape 1 — Simuler une avancée distante par Bob et observer via git fetch
Bob publie un commit sur `origin/main`. Depuis le poste d'Alice, inspectez les remotes, téléchargez l'état du serveur sans modifier votre branche grâce à `git fetch`, puis intégrez proprement :

```bash
# Bob pushes a commit to origin/main
cd /tmp/lp-git-lab03/dev-bob
echo "# Operations Guide" > OPS.md
git add OPS.md
git commit -m "docs: add operations guide"
git push origin main

# Alice safely inspects remote updates with git fetch before pulling
cd /tmp/lp-git-lab03/dev-alice
git remote -v
git fetch origin
git log --oneline main..origin/main
git pull --ff-only origin main
```

### Étape 2 — Publier une branche de Pull Request avec suivi amont (git push -u)
Alice crée la branche isolée `feat/healthcheck-endpoint`, implémente le module `health.py`, valide le commit et pousse la branche avec `-u` pour configurer le suivi distant :

```bash
# Alice creates and publishes a feature branch for Pull Request review
cd /tmp/lp-git-lab03/dev-alice
git switch -c feat/healthcheck-endpoint

cat <<'EOF' > health.py
def health_status() -> dict[str, str]:
    return {"status": "ok", "service": "billing-api"}
EOF

git add health.py
git commit -m "feat(health): add service healthcheck helper"
git push -u origin feat/healthcheck-endpoint
git branch -vv
```

### Étape 3 — Fusionner la Pull Request dans main et resynchroniser l'équipe
Simulez l'approbation et l'intégration de la PR dans `main` (avec `--no-ff`), publiez `main`, supprimez la branche distante fusionnée, puis mettez à jour le poste de Bob avec `git pull --prune` :

```bash
# Merge approved PR branch into main and clean remote/local branches
cd /tmp/lp-git-lab03/dev-alice
git switch main
git merge --no-ff feat/healthcheck-endpoint -m "merge: accept PR feat/healthcheck-endpoint"
git push origin main
git push origin --delete feat/healthcheck-endpoint
git branch -d feat/healthcheck-endpoint

# Bob synchronizes main and prunes stale remote tracking branches
cd /tmp/lp-git-lab03/dev-bob
git pull --prune origin main
git log --oneline --graph --decorate
```

### Résultats Attendus (Artefact Témoin)

```text
*   a2c4f91 (HEAD -> main, origin/main) merge: accept PR feat/healthcheck-endpoint
|\
| * 5d81b03 feat(health): add service healthcheck helper
|/
* 8e12d44 docs: add operations guide
* 3a09f11 chore: bootstrap application skeleton
```

### 🏆 Défi Expert

Simulez un conflit d'envoi (`[rejected] non-fast-forward`) : faites avancer `origin/main` depuis `dev-bob` avec un fichier `CHANGELOG.md`, puis créez un commit local concurrent sur `dev-alice` dans `NOTES.md`, tentez `git push`, récupérez proprement avec `git pull --rebase origin main` et terminez par `git push`.

* **Commandes Solutions :**

```bash
# Simulate rejected push and recover cleanly with git pull --rebase
cd /tmp/lp-git-lab03/dev-bob
echo "v1.0.1" > CHANGELOG.md
git add CHANGELOG.md && git commit -m "docs: add changelog" && git push origin main

cd /tmp/lp-git-lab03/dev-alice
echo "Sprint 1 notes" > NOTES.md
git add NOTES.md && git commit -m "docs: add sprint notes"
git push origin main || true
git pull --rebase origin main
git push origin main
```

---

## 3. Commande de Validation

Exécutez cet oracle Bash automatisé pour certifier la synchronisation du dépôt `--bare` et des deux clones développeurs :

```bash
# Automated Qualiopi validation oracle for Lab 03
set -euo pipefail

bare_head="$(git --git-dir=/tmp/lp-git-lab03/origin-repo.git rev-parse refs/heads/main)"
alice_head="$(git -C /tmp/lp-git-lab03/dev-alice rev-parse HEAD)"
bob_head="$(git -C /tmp/lp-git-lab03/dev-bob rev-parse HEAD)"

if [ "$alice_head" != "$bare_head" ] || [ "$bob_head" != "$bare_head" ]; then
  echo "ERREUR : les clones Alice et Bob doivent pointer exactement sur origin/main." >&2
  exit 1
fi

if [ ! -f /tmp/lp-git-lab03/dev-bob/health.py ] || [ ! -f /tmp/lp-git-lab03/dev-alice/OPS.md ]; then
  echo "ERREUR : fichiers synchronisés attendus manquants (health.py ou OPS.md)." >&2
  exit 1
fi

if git --git-dir=/tmp/lp-git-lab03/origin-repo.git show-ref --verify refs/heads/feat/healthcheck-endpoint >/dev/null 2>&1; then
  echo "ERREUR : la branche distante feat/healthcheck-endpoint doit être supprimée après merge PR." >&2
  exit 1
fi

echo "VALIDATION_OK : Atelier 03 validé (origin/main, Alice et Bob synchronisés sur $bare_head)."
```

---

## Grille d'Évaluation de Conformité Opérationnelle (DoD)

| Critère Qualiopi | Indicateur Vérifiable | Statut Cible |
| :--- | :--- | :--- |
| Inspection sécurisée | Utilisation de `git remote -v` et `git fetch origin` avant intégration | Obligatoire |
| Suivi amont PR | Publication via `git push -u origin feat/healthcheck-endpoint` | Obligatoire |
| Intégration & Nettoyage | Merge dans `main` puis suppression locale et distante de la branche PR | Obligatoire |
| Convergence équipe | `origin/main`, `dev-alice` et `dev-bob` alignés sur le même SHA | Obligatoire |

---

## 4. Corrigé Détaillé & Débriefing REX Expert

* **Pourquoi utiliser un dépôt `--bare` dans l'atelier ?** Un dépôt nu reproduit fidèlement le comportement d'un serveur GitHub, GitLab ou Gitea en acceptant les `git push` sans répertoire de travail ouvert.
* **Piège fréquent en production :** contourner un rejet `non-fast-forward` par `git push --force` sur `main` efface les commits fraîchement poussés par un collègue.
* **REX Terrain :** configurez `git config --global pull.rebase true` et `git config --global fetch.prune true` pour conserver des historiques linéaires et purger automatiquement les références distantes supprimées.

---

## 🌟 Pour Aller Plus Loin

* Vérifiez l'état final de `dev-alice` avec `python3 pgscripts/pg_git_repo_audit.py --repo /tmp/lp-git-lab03/dev-alice`.
* Explorez le script `04_ateliers_et_tp/pgexamples/python/check_commit_msg.py` pour automatiser la qualité des messages avant chaque `git push`.
