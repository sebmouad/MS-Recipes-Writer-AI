# MS Recipes Writer AI

Plugin WordPress en construction pour la génération éditoriale culinaire orchestrée.

## État actuel

La version `0.1.0` fournit un socle installable et sans appel payant automatique :

- modes administrateur `Automatique` / `Manuel` ;
- limites initiales de 50 recettes par lot, 4 traitements simultanés et 2 corrections ;
- tables persistantes pour lots, jobs et événements ;
- écran MS Tools (ou menu autonome si MS Tools est absent) ;
- réglages protégés des clés API et prompts éditables ;
- catalogue de capacités et tarifs vérifiés des modèles OpenAI, Gemini et Claude ;
- préfiltre de routage automatique qualité/coût et validation du schéma canonique ;
- snapshot des réglages et modèles sélectionnés pour chaque lot et chaque job ;
- sélection manuelle distincte par étape (rédaction, relecture, images, recherche) ;
- REST local pour créer et consulter les lots ;
- file durable qui place les lots en attente de validation du budget de test.

Le pipeline externe, les appels fournisseurs réels et la création de brouillon WordPress seront ajoutés après validation des contrats et d’un budget de tests payants. Aucune clé n’est incluse dans le dépôt.

## Installation locale

1. Installer le dossier dans `wp-content/plugins/ms-recipes-writer-ai/`.
2. Activer **MS Recipes Writer AI**.
3. Ouvrir **MS Tools → MS Recipes Writer AI → Réglages**.
4. Configurer les prompts et, si nécessaire, les clés côté serveur. Une clé seule ne déclenche pas d’appel payant.
5. Utiliser l’écran principal pour créer un lot de titres.

Les tables sont préfixées par la base WordPress et sont créées à l’activation. La désactivation retire uniquement la planification du plugin ; elle ne supprime pas les données ni les médias.

## Documentation de conception

Le plan complet et les critères d’acceptation se trouvent dans [`../MS-Recipes-Writer-AI-PLAN-FINAL.md`](../MS-Recipes-Writer-AI-PLAN-FINAL.md).
