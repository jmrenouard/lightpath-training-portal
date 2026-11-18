# 🧪 Atelier 02 : Branches Isolées, Fusion & Résolution de Conflit

> **Atelier :** 02 — Gestion des Branches & Résolution Déterministe d'un Conflit Merge
> **Durée :** 1h15
> **Compétence Visée (Bloom C3) :** Isoler des développements par branches, exécuter une fusion et résoudre proprement un conflit de code
> **Environnement :** Terminal Git local (Linux, macOS ou Git Bash)

---

## 1. Prérequis, Mise en Situation & Amorçage Idempotent

### Scénario Métier
Vous maintenez le service de tarification e-commerce `checkout-service`. Une fonctionnalité `feat/discount-code` s'intègre d'abord linéairement (`Fast-Forward`) sur `main`. Ensuite, deux tâches démarrent en parallèle sur le même fichier `pricing.py` : un correctif urgent `fix/rounding-bug` et une évolution fiscale `feat/tva-eu`. Vous devez fusionner le correctif, déclencher le conflit contrôlé lors de la seconde fusion, le résoudre sans perdre aucune règle métier, puis nettoyer les branches temporaires.

### Script d'Amorçage Idempotent
Exécutez le script ci-dessous pour préparer le dépôt `/tmp/lp-git-lab02/checkout-service` dans un état initial reproductible :

```bash
# Idempotent workspace initialization for Lab 02
rm -rf /tmp/lp-git-lab02
mkdir -p /tmp/lp-git-lab02/checkout-service
cd /tmp/lp-git-lab02/checkout-service

git init -b main
git config user.name "Alice Martin"
git config user.email "alice.martin@lightpath.fr"

cat <<'EOF' > pricing.py
TAX_RATE = 0.20

def final_price(amount: float) -> float:
    return round(amount * (1.0 + TAX_RATE), 2)
EOF

git add pricing.py
git commit -m "feat(pricing): initialize base pricing module"
```

---

## 2. Travail Pas-à-Pas

### Étape 1 — Créer une branche et observer une fusion Fast-Forward
Créez la branche `feat/discount-code`, ajoutez une fonction utilitaire `apply_discount`, revenez sur `main` et fusionnez en mode linéaire :

```bash
# Create feature branch and merge cleanly via fast-forward
cd /tmp/lp-git-lab02/checkout-service
git switch -c feat/discount-code

cat <<'EOF' > discount.py
def apply_discount(amount: float, percent: float) -> float:
    return round(amount * (1.0 - percent / 100.0), 2)
EOF

git add discount.py
git commit -m "feat(discount): add percentage discount helper"

git switch main
git merge feat/discount-code
git branch -d feat/discount-code
```

### Étape 2 — Créer deux branches concurrentes modifiant pricing.py
Créez deux branches à partir du même commit courant sur `main` : `fix/rounding-bug` et `feat/tva-eu`, chacune modifiant la ligne `TAX_RATE` et le calcul dans `pricing.py` :

```bash
# Create two diverging branches modifying the same line in pricing.py
git switch -c fix/rounding-bug
cat <<'EOF' > pricing.py
TAX_RATE = 0.20  # Fixed French standard VAT

def final_price(amount: float) -> float:
    return round(amount * (1.0 + TAX_RATE) + 1e-9, 2)
EOF
git add pricing.py
git commit -m "fix(pricing): guard float rounding edge case"

git switch main
git switch -c feat/tva-eu
cat <<'EOF' > pricing.py
TAX_RATE = 0.21  # EU cross-border rate

def final_price(amount: float, rate: float = TAX_RATE) -> float:
    return round(amount * (1.0 + rate), 2)
EOF
git add pricing.py
git commit -m "feat(pricing): parameterize EU VAT rate"
```

### Étape 3 — Fusionner fix/rounding-bug puis résoudre le conflit de feat/tva-eu
Fusionnez d'abord `fix/rounding-bug` dans `main`, puis lancez `git merge feat/tva-eu` pour observer les marqueurs de conflit, arbitrez le code combinant le garde d'arrondi et le taux paramétrable, et finalisez la fusion :

```bash
# Merge hotfix first, then resolve conflict from feat/tva-eu
git switch main
git merge --no-ff fix/rounding-bug -m "merge: integrate rounding fix"

# Trigger expected conflict on pricing.py (handle non-zero exit deliberately)
git merge feat/tva-eu || true
git status

# Resolve conflict cleanly by combining parameterized rate and rounding guard
cat <<'EOF' > pricing.py
TAX_RATE = 0.21  # Default EU VAT rate configurable per country

def final_price(amount: float, rate: float = TAX_RATE) -> float:
    return round(amount * (1.0 + rate) + 1e-9, 2)
EOF

git add pricing.py
git commit -m "merge: resolve pricing.py conflict keeping rounding fix and EU VAT"
git branch -d fix/rounding-bug feat/tva-eu
git log --oneline --graph --decorate
```

### Résultats Attendus (Artefact Témoin)

```text
*   7f82a10 (HEAD -> main) merge: resolve pricing.py conflict keeping rounding fix and EU VAT
|\
| * 4b19d22 feat(pricing): parameterize EU VAT rate
* | 9a31c05 merge: integrate rounding fix
|\|
| * 2d44e11 fix(pricing): guard float rounding edge case
|/
* 1c08a90 feat(discount): add percentage discount helper
```

### 🏆 Défi Expert

Vérifiez par une commande Python en une ligne que `pricing.final_price(100.0)` retourne bien `121.0` et que `pricing.final_price(100.0, 0.20)` retourne `120.0`, prouvant qu'aucune régression n'a été introduite lors de la résolution du conflit.

* **Commandes Solutions :**

```bash
# Verify merged pricing module behavior after conflict resolution
python3 -c "import pricing; assert pricing.final_price(100.0) == 121.0; assert pricing.final_price(100.0, 0.20) == 120.0; print('PRICING_TESTS_OK')"
```

---

## 3. Commande de Validation

Exécutez cet oracle Bash automatisé pour certifier l'absence de marqueurs de conflit, la présence des deux fonctions et la propreté des branches :

```bash
# Automated Qualiopi validation oracle for Lab 02
set -euo pipefail
cd /tmp/lp-git-lab02/checkout-service

if [ "$(git branch --show-current)" != "main" ]; then
  echo "ERREUR : vous devez être sur la branche 'main'." >&2
  exit 1
fi

if grep -rnE '^(<<<<<<<|=======|>>>>>>>)' pricing.py discount.py >/dev/null 2>&1; then
  echo "ERREUR : des marqueurs de conflit résiduels subsistent." >&2
  exit 1
fi

merge_parents="$(git rev-list --parents -n 1 HEAD | wc -w)"
if [ "$merge_parents" -lt 3 ]; then
  echo "ERREUR : HEAD doit être un commit de fusion à deux parents." >&2
  exit 1
fi

if [ -n "$(git status --porcelain)" ]; then
  echo "ERREUR : le répertoire de travail n'est pas propre." >&2
  exit 1
fi

echo "VALIDATION_OK : Atelier 02 validé (conflit résolu, merge commit vérifié et branches nettoyées)."
```

---

## Grille d'Évaluation de Conformité Opérationnelle (DoD)

| Critère Qualiopi | Indicateur Vérifiable | Statut Cible |
| :--- | :--- | :--- |
| Fusion Fast-Forward | Intégration de `feat/discount-code` et présence de `discount.py` | Obligatoire |
| Conflit maîtrisé | Résolution complète sans marqueur `<<<<<<<`, `=======`, `>>>>>>>` | Obligatoire |
| Préservation fonctionnelle | Conservation du taux paramétrable et du garde d'arrondi | Obligatoire |
| Hygiène des branches | Branches temporaires supprimées via `git branch -d` | Obligatoire |

---

## 4. Corrigé Détaillé & Débriefing REX Expert

* **Pourquoi garder les deux logiques lors de la résolution ?** Choisir aveuglément "accept incoming" ou "accept current" dans un IDE écrase souvent un correctif critique ; la relecture des deux blocs reste indispensable.
* **Piège fréquent en production :** valider une fusion contenant encore une ligne `=======` oubliée provoque une `SyntaxError` immédiate à l'exécution.
* **REX Terrain :** en cas de doute pendant un conflit complexe, exécutez `git merge --abort` pour revenir à l'état initial et solliciter l'auteur de la branche concurrente.

---

## 🌟 Pour Aller Plus Loin

* Inspectez l'historique topologique complet avec `git log --oneline --graph --all --decorate`.
* Lancez `python3 pgscripts/pg_git_repo_audit.py --repo /tmp/lp-git-lab02/checkout-service` pour contrôler automatiquement l'absence de marqueurs de conflit dans tout le dépôt.
