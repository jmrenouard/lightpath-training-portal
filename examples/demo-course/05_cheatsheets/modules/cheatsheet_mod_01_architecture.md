# 📋 Fiche Mémo Module 01 : Architecture PostgreSQL 18

> **Référence :** Module 01 (Démo Open-Source MIT)
> **Formateur :** Jean-Marie Renouard — LightPath SARL

## Processus Clés

| Processus | Rôle Opérationnel |
| :--- | :--- |
| `postmaster` | Processus père, écoute TCP/socket et lance les backends |
| `backend` | Processus dédié à une connexion client SQL |
| `checkpointer` | Écrit les pages modifiées (`dirty buffers`) et marque un point de reprise WAL |
| `walwriter` | Vide périodiquement `wal_buffers` vers les fichiers `pg_wal` |
| `autovacuum` | Nettoie les tuples morts MVCC et met à jour les statistiques |

## Commandes Express de Diagnostic

```sql
-- Check PostgreSQL version and active sessions
SELECT version();
SELECT pid, backend_type, state FROM pg_stat_activity;

-- Inspect memory and I/O configuration
SHOW shared_buffers;
SHOW work_mem;
SHOW io_method;
```
