# Règles d'Ingénierie & Workflow Git — LightPath Training Portal

1. **Workflow Git Strict & Systématique en 9 Étapes (Obligatoire sur Tous les Repos) :**
   - 0. **Ouverture d'Issue GitHub (`gh issue create`) :** Chaque tâche, évolution ou correction de bug doit obligatoirement faire l'objet d'une **Issue GitHub** préalable (`gh issue create`) sur les dépôts concernés (`lightpath-training-portal` et `training_postgresql`), avec titre et description strictement rédigés en anglais.
   - 1. **Branche dédiée systématique :** Ne jamais commiter directement sur `main`. Toujours créer une branche thématique dédiée (`feat/...`, `fix/...`, `docs/...`, `chore/...`).
   - 2. **Validation préalable par les tests :** Exécution obligatoire de `php tests/run_tests.php` (100% de réussite exigée) avant toute étape d'indexation ou de commit.
   - 3. **Indexation rigoureuse (`git add`) :** Indexer uniquement les fichiers suivis modifiés via `git add`. Exclure strictement les caches, dumps et artefacts temporaires.
   - 4. **Commit structuré en anglais (`git commit`) :** Messages de commit au format Conventional Commits strictement en anglais (`git commit`).
   - 5. **Push de la branche de travail (`git push`) :** Pousser la branche de travail vers le dépôt distant (`git push -u origin <nom-de-branche>`).
   - 6. **Ouverture de Pull Request en anglais (`gh pr create`) :** Ouvrir une PR en anglais (`gh pr create`) ciblant `main` référençant l'Issue (`Closes #<id>`).
   - 7. **Merge de la Pull Request en anglais (`gh pr merge`) :** Fusionner la PR (`gh pr merge`) dès validation des tests avec message de fusion en anglais.
   - 8. **Fermeture de l'Issue avec commentaire en anglais (`gh issue close`) :** Fermer systématiquement l'Issue GitHub avec un commentaire détaillé en anglais référençant la PR fusionnée et les résultats de tests (`gh issue close <num> --comment "..."`) après le merge.
   - **Zéro Français sur GitHub :** Issues, PRs, revues, commits, fusions et commentaires de clôture doivent être 100% en anglais.

2. **Commentaires de Code Source Strictement en Anglais :**
   - Tous les commentaires de code (PHP, JavaScript, CSS, HTML, Bash, SQL) au sein du portail LMS doivent être STRICTEMENT rédigés en anglais technique. Zéro commentaire en français dans le code source.

3. **Design & Qualité Studio LightPath SARL :**
   - Palette Obsidian `#0B1120`, Cyan `#00B0F0`, chiffres tabulaires `tabular-nums`, autonomie totale (zéro CDN externe).
   - Branding exclusif LightPath SARL — Formateur : Jean-Marie Renouard.
   - Barres verticales gauche et droite repliables à la demande ; barre verticale de droite sticky (`overflow-x: clip` sur `body`) et sans aucune barre de défilement visible (`scrollbar-width: none`).
