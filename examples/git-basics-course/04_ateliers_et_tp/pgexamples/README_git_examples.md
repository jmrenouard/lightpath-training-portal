# 💻 Exemples Compagnons Git (Python 3 Standard Library)

> **Formation :** Git — Les Bases Essentielles du Versionnement & de la Collaboration
> **Formateur :** Jean-Marie Renouard — LightPath SARL

Ce répertoire fournit un exemple compagnon autonome illustrant l'automatisation locale des contrôles qualité dans un dépôt Git sans aucune dépendance tierce.

---

## 1. Validateur Conventional Commits (`python/check_commit_msg.py`)

Ce script vérifie qu'un sujet de commit respecte la convention `type(portée): description` (`feat:`, `fix:`, `docs:`, `chore:`, `merge:`).

```bash
# Validate a commit subject directly from the command line
python3 04_ateliers_et_tp/pgexamples/python/check_commit_msg.py --message "feat(billing): add VAT helper"

# Install locally as an executable Git commit-msg hook
cp 04_ateliers_et_tp/pgexamples/python/check_commit_msg.py .git/hooks/commit-msg
chmod +x .git/hooks/commit-msg
```

---

## 2. Valeurs de Retour

* **`0` :** le sujet respecte le format attendu.
* **`1` :** le sujet est vide ou non conforme (bloque le `git commit` si utilisé en hook).
* **`2` :** arguments CLI invalides ou fichier de message introuvable.
