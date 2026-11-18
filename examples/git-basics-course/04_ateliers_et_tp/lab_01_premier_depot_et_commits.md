# 🧪 Atelier 01 : Premier Dépôt, Index (Staging) & Commits Atomiques

> **Atelier :** 01 — Initialisation & Hygiène d'un Dépôt Git Local (`billing-api`)
> **Durée :** 1h00
> **Compétence Visée (Bloom C2) :** Initialiser un dépôt Git propre, exclure les fichiers sensibles et valider des commits atomiques
> **Environnement :** Terminal Git local (Linux, macOS ou Git Bash)

---

## 1. Prérequis, Mise en Situation & Amorçage Idempotent

### Scénario Métier
Vous démarrez le développement du microservice Python `billing-api` chargé de générer les factures clients d'une plateforme SaaS. Avant de partager le code avec l'équipe, vous devez initialiser le dépôt local sur la branche `main`, protéger les secrets `.env` et les journaux `*.log` via `.gitignore`, inspecter vos différences entre Working Directory et Index, puis enregistrer deux commits atomiques conformes aux Conventional Commits.

### Script d'Amorçage Idempotent
Exécutez le script Bash ci-dessous pour créer un espace de travail propre dans `/tmp/lp-git-lab01` (réexécutable à l'identique) :

```bash
# Idempotent workspace initialization for Lab 01
rm -rf /tmp/lp-git-lab01
mkdir -p /tmp/lp-git-lab01/billing-api/app
cd /tmp/lp-git-lab01/billing-api

cat <<'EOF' > app/invoice.py
def compute_ttc(amount_ht: float, vat_rate: float = 0.20) -> float:
    return round(amount_ht * (1.0 + vat_rate), 2)
EOF

cat <<'EOF' > .env
STRIPE_API_KEY=sk_test_do_not_commit_12345
EOF

echo "DEBUG startup billing-api" > app.log
```

---

## 2. Travail Pas-à-Pas

### Étape 1 — Initialiser le dépôt et configurer l'identité locale
Initialisez le dépôt sur la branche par défaut `main` et configurez une identité auteur locale propre à ce projet :

```bash
# Initialize repository with main as initial branch and set local author identity
cd /tmp/lp-git-lab01/billing-api
git init -b main
git config user.name "Alice Martin"
git config user.email "alice.martin@lightpath.fr"
git status
```

### Étape 2 — Déployer .gitignore et valider le premier commit
Créez un fichier `.gitignore` excluant `.env`, `*.log` et `__pycache__/`, vérifiez que les secrets disparaissent de `git status`, puis validez `.gitignore` et `app/invoice.py` :

```bash
# Create .gitignore before staging application code
cat <<'EOF' > .gitignore
.env
*.log
__pycache__/
EOF

git status
git add .gitignore app/invoice.py
git diff --staged
git commit -m "feat(billing): initialize repository and invoice helper"
```

### Étape 3 — Ajouter la documentation et inspecter l'historique
Ajoutez un fichier `README.md`, vérifiez l'écart entre répertoire de travail et Index avec `git diff`, puis enregistrez un second commit atomique :

```bash
# Add project README and create a second atomic commit
cat <<'EOF' > README.md
# Billing API

Microservice de calcul et génération de factures.
EOF

git add README.md
git commit -m "docs: add billing-api README"
git log --oneline --graph --decorate
```

### Résultats Attendus (Artefact Témoin)

```text
* e4a19b2 (HEAD -> main) docs: add billing-api README
* 91c03f8 feat(billing): initialize repository and invoice helper
```

### 🏆 Défi Expert

Modifiez `app/invoice.py` pour ajouter une vérification levant `ValueError` si `amount_ht < 0`, indexez ce changement, vérifiez `git diff --staged`, puis corrigez le message du dernier commit ou créez un troisième commit `fix(billing): reject negative invoice amount` et vérifiez qu'aucun fichier `.env` n'est suivi par `git ls-files`.

* **Commandes Solutions :**

```bash
# Add negative amount validation and verify tracked files
cat <<'EOF' > app/invoice.py
def compute_ttc(amount_ht: float, vat_rate: float = 0.20) -> float:
    if amount_ht < 0:
        raise ValueError("amount_ht must be non-negative")
    return round(amount_ht * (1.0 + vat_rate), 2)
EOF

git add app/invoice.py
git diff --staged
git commit -m "fix(billing): reject negative invoice amount"
git ls-files
```

---

## 3. Commande de Validation

Exécutez cet oracle Bash automatisé pour certifier la conformité complète de l'atelier :

```bash
# Automated Qualiopi validation oracle for Lab 01
set -euo pipefail
cd /tmp/lp-git-lab01/billing-api

branch="$(git branch --show-current)"
commit_count="$(git rev-list --count HEAD)"

if [ "$branch" != "main" ]; then
  echo "ERREUR : branche attendue 'main', trouvée '$branch'." >&2
  exit 1
fi

if [ "$commit_count" -lt 2 ]; then
  echo "ERREUR : au moins 2 commits requis, trouvé $commit_count." >&2
  exit 1
fi

if git ls-files --error-unmatch .env >/dev/null 2>&1; then
  echo "ERREUR : .env ne doit jamais être suivi par Git." >&2
  exit 1
fi

if [ -n "$(git status --porcelain)" ]; then
  echo "ERREUR : le répertoire de travail contient des fichiers non validés." >&2
  exit 1
fi

echo "VALIDATION_OK : Atelier 01 validé sur branche '$branch' avec $commit_count commits propres."
```

---

## Grille d'Évaluation de Conformité Opérationnelle (DoD)

| Critère Qualiopi | Indicateur Vérifiable | Statut Cible |
| :--- | :--- | :--- |
| Initialisation propre | Dépôt initialisé sur `main` avec `user.name` et `user.email` | Obligatoire |
| Sécurité `.gitignore` | `.env` et `app.log` absents de `git ls-files` et `git status` | Obligatoire |
| Historique atomique | Au moins 2 commits structurés visibles dans `git log --oneline` | Obligatoire |
| Oracle automatisé | Sortie `VALIDATION_OK` et code de retour `0` | Obligatoire |

---

## 4. Corrigé Détaillé & Débriefing REX Expert

* **Pourquoi créer `.gitignore` en priorité ?** En initialisant `.gitignore` avant le tout premier `git add`, vous éliminez le risque d'exposer des clés d'API ou des fichiers volumineux dans les objets `.git/objects` dès l'origine du dépôt.
* **Piège fréquent en production :** lancer `git add .` sans exécuter `git status` au préalable entraîne fréquemment l'indexation de journaux `.log` ou de fichiers `.env`.
* **REX Terrain :** adoptez systématiquement `git diff --staged` comme filet de sécurité avant chaque `git commit` afin de relire vos modifications ligne par ligne.

---

## 🌟 Pour Aller Plus Loin

* Testez le validateur Python compagnon `04_ateliers_et_tp/pgexamples/python/check_commit_msg.py` sur vos messages de commit.
* Exécutez `python3 pgscripts/pg_git_repo_audit.py --repo /tmp/lp-git-lab01/billing-api` pour auditer l'hygiène complète du dépôt en JSON.
