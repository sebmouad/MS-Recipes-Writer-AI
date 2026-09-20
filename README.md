# MS Recipes Writer AI

Plugin WordPress en construction pour la génération éditoriale culinaire orchestrée.

## État actuel

La version `0.2.56` est un socle installable : file persistante, pipeline de
génération, contrôle qualité déterministe, budgets, images et écrans
d’administration. Le détail des fonctionnalités livrées se trouve dans
l’historique des versions ci-dessous.

Le plugin est en cours de refonte éditoriale et budgétaire :

- [`docs/PLAN.md`](docs/PLAN.md) — ce qui est construit, en clair.
- [`docs/BUILD-CHECKLIST.md`](docs/BUILD-CHECKLIST.md) — les tâches à réaliser,
  leurs tests et leur critère d’achèvement.
- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — le code tel qu’il existe.
- [`docs/TESTING.md`](docs/TESTING.md) — suite hors ligne et tests réels.

Les coûts affichés sont des estimations calculées avec le catalogue configuré,
non une facture fournisseur. `completed` signifie que le traitement est terminé,
jamais qu’un texte est validé éditorialement.

## Version 0.2.56

Le collage Facebook est repassé au banc d'essai avec l'étape d'approbation, et
le résultat contredit l'hypothèse de départ.

**La qualité n'est pas le levier.** L'image mise en avant est jugée `good`,
verdict et réalisme, à **toutes les qualités, `low` comprise** : monter sa
qualité n'achète aucune amélioration détectable. Le collage échoue en revanche
aux deux qualités, et toujours des deux mêmes façons — panneaux dans le
désordre, et vaisselle de service qui n'apparaît nulle part ailleurs. En `high`,
à 2,5× le prix, un tirage sur deux est encore refusé.

Une partie de la cause était dans notre propre prompt : les rôles génériques
plaçaient « whisking, mixing » en panneau 2, ce qui, pour une tarte, fait battre
l'appareil avant même le fonçage de la pâte — exactement l'erreur relevée deux
fois par le juge. Les rôles sont désormais subordonnés à l'ordre canonique de la
recette, et le panneau 6 doit servir le plat dans le contenant décrit par les
observations. Le `medium` passe de 0/3 à 1/3 approuvé : un progrès, pas une
solution.

Les huit tirages, leurs coûts et leurs verdicts sont consignés dans
[`docs/LAB-RESULTS.md`](docs/LAB-RESULTS.md), avec les deux routes chiffrées
pour la suite — réessayer jusqu'à approbation (~0,077 $ le collage accepté) ou
générer six panneaux séparés et composer la grille nous-mêmes (0,062 $ en `low`,
0,106 $ en `medium`, composition gratuite et ordre garanti par construction).

## Version 0.2.55

**La qualité se règle image par image, `medium` par défaut pour les deux.**
Les deux visuels n'ont ni le même coût ni le même rôle : une seule case
« Qualité OpenAI » les traitait pourtant ensemble, et elle était livrée sur
`low`.

Mesuré le 20/09/2026, `gpt-image-2.5-flare`, image principale 1024×1024 :

| Qualité | Jetons image | Temps | Coût |
|---|---:|---:|---:|
| `low` | 196 | 8,7 s | 0,0104 $ |
| `medium` | 439 | 10,1 s | 0,0177 $ |
| `high` | 1 756 | 18,0 s | 0,0572 $ |

Le rapport livré était généré en `high` : 0,0708 $ pour l'image principale et
0,0662 $ pour le collage, soit 0,137 $ — **74 % du coût total de 0,1856 $**. En
`medium`, les deux ensemble reviennent à 0,038 $.

- Un sélecteur par image dans l'écran de réglages, avec les coûts mesurés
  affichés sous le champ.
- L'estimation budgétaire lit désormais la qualité propre à chaque image ; elle
  appliquait une valeur unique aux deux, donc elle se trompait dès que les deux
  différaient.
- L'ancienne clé `image_quality` reste lue en repli : un site déjà configuré
  garde son choix.
- Le laboratoire lit la valeur livrée au lieu d'un `medium` codé en dur, sinon
  il ne mesure pas ce qui part en production.

## Version 0.2.54

**L'étape d'approbation finale existe.** C'était la seule des cinq étapes
convenues sans rien derrière : le plugin possédait `prompt_image_review`, jamais
mesuré, qui jugeait une image à la fois. Dans le rapport livré, les visuels
étaient validés « contrôle visuel manuel », à l'œil, et les corrections demandées
par les relectures étaient appliquées à la main.

Un seul appel voit désormais l'article et les deux images **ensemble** — le seul
moment où les trois peuvent être confrontés. Il rend un verdict par artefact, le
réalisme jugé à part de la fidélité, le nombre de panneaux réellement comptés,
et des constats citant la phrase exacte en faute.

Réglé contre de vrais artefacts, quatre itérations mesurées :

1. Le réalisme et la fidélité étaient dans la même règle : le juge validait une
   image « bonne » tout en décrivant un ingrédient absent. Séparés.
2. Trop strict ensuite : une plante en pot du décor était signalée comme
   ingrédient. Le décor n'est plus un constat.
3. Trop strict encore : le riz blanc bloquait, alors que le yassa se sert avec du
   riz et que la recherche le confirme. Un accompagnement documenté n'est plus un
   constat.
4. Tout devenait « bloquant » — 17 constats bloquants sur une tarte. Une porte qui
   ne s'ouvre jamais ne sert à rien. La sévérité est maintenant définie : 17
   constats sont devenus 3, dont 2 bloquants.

Résultat sur deux plats, `gpt-5.6-luna`, 9/9 à chaque fois, ~22s et $0.005 :
refus motivé des deux côtés — une garniture verte absente de la recette sur le
dernier panneau du collage, et une rupture de vaisselle entre le collage et
l'image mise en avant. Aucun faux positif sur le riz, le laurier, le piment ni
le décor.

- `approval_max_output_tokens` (6 000) : le premier essai s'est terminé en
  `incomplete` à 3 000.
- Le score refusait de sanctionner une réponse illisible : trois contrôles
  passaient sur zéro constat. Une réponse illisible échoue désormais partout.

## Version 0.2.53

La recette canonique fournit enfin les données structurées Google attendues, et
une liste d'ingrédients avec laquelle on peut réellement cuisiner.

- **`recipe_category` et `description`** sont produits et enregistrés : Google
  lit le premier comme cours du repas, le second comme phrase affichée dans les
  résultats de recherche. Aucun des deux n'existait.
- **`total_minutes`** était produit depuis toujours mais absent de
  `integration_mapping` : il n'atteignait jamais l'article. Il est désormais
  mappé et vérifié.
- **`canonical_max_output_tokens` passe de 2 600 à 4 500.** Le plafond était
  déjà trop bas avant cet ajout : la recette du rapport livré consommait 3 260
  jetons de sortie, et un premier essai s'est terminé en `incomplete` à 2 600
  exactement.
- **Liste d'ingrédients cuisinable.** La règle « n'invente rien » supprimait les
  ingrédients de base : un poulet yassa revenait avec quatre ingrédients, sans
  huile ni sel. La recette doit maintenant permettre de cuisiner sans deviner —
  matière grasse, sel, poivre, eau ou bouillon quand la méthode les exige — tout
  en continuant d'interdire l'invention d'un ingrédient distinctif. Mesuré sur
  `gpt-5.6-luna` : 4 ingrédients et 5 étapes avant, 7 et 14 après ; la tarte
  normande passe de 4 à 9 ingrédients. Les deux fiches passent 4/4.
- La notation `[string]` du contrat était ambiguë et le modèle renvoyait
  `keywords` en une seule chaîne séparée par des virgules. Le contrat dit
  désormais « array of strings », et le contrôle accepte les deux formes, que le
  publisher sait déjà lire.

## Version 0.2.52

La recherche devient la source d'une **apparence vérifiée** plutôt que devinée.
Elle cite des photographies réelles du plat — jamais générées, retouchées ni
composées — et le laboratoire télécharge chaque image citée pour l'analyser
octet par octet ; ce que la vision y observe descend ensuite dans la recette
canonique, l'article et les deux prompts d'image.

**Aucune photographie n'est republiée.** L'image sert à regarder, pas à
illustrer : les observations remplacent la photo, qui reste à son auteur. Une
observation ne peut établir qu'une apparence — jamais un ingrédient caché, une
quantité ni une étape de préparation.

- `prompt_research` livré correspond enfin au prompt mesuré au laboratoire : il
  exige la provenance (`image_url` et `source_url` en HTTPS) et interdit de
  deviner l'apparence depuis un extrait de recherche.
- `tests/test-visual-reference.php` vérifie que la recette, l'article et la
  relecture reçoivent encore le dossier de recherche. Une étape qui cesse de le
  recevoir n'échoue pas : elle invente une apparence sans rien signaler.
- `docs/LAB-RESULTS.md` consigne les 51 appels réels déjà mesurés — modèle,
  durée, coût, score, cause d'arrêt — pour que chaque chiffre soit reproductible
  ou contestable.
- `research_facts_max` et `research_references_max` deviennent réglables.

Une approche concurrente, mesurée puis écartée le même jour, faisait décrire les
photographies au modèle de mémoire, en six facettes nommées. Elle obtenait 8/8
sur deux plats et amenait l'article à 10/10, `visual_final_notes` compris — la
seule vérification qui échouait encore sur les neuf cellules d'article. Lire les
octets reste une preuve plus solide qu'un modèle décrivant une page consultée.
Le raisonnement est conservé dans `docs/BUILD-CHECKLIST.md`, tâche A3.

## Version 0.2.51

Une réponse coupée à `max_tokens` se termine au milieu d'un caractère UTF-8, et
ce seul caractère cassé fait échouer `json_encode` sur toute la chaîne : Sonnet 5
a facturé 14 500 jetons de sortie le 20/09/2026 et la réponse a été enregistrée
vide. `MSRWA_Json::valid_utf8` nettoie la fin d'une réponse tronquée avant
lecture et avant enregistrement, dans le moteur comme au laboratoire.

**Ce que cela ne corrige pas :** sur l'étape article, Sonnet 5 dépasse encore le
plafond de 14 500 jetons. Le raisonnement étendu n'est pas en cause (vérifié :
`thinking_tokens` à 0 par défaut) ; le modèle écrit simplement beaucoup plus
long. À trancher avant la phase B.

## Version 0.2.50

Corrige deux pertes de résultat mesurées sur de vraies réponses de modèles, le
20/09/2026.

- **Lecture du JSON.** `MSRWA_Json` répare ce qu'un modèle écrit réellement :
  une phrase avant l'objet (Opus 5 en recherche web), un retour à la ligne brut
  laissé dans une chaîne de 18 Ko (Sonnet 5 sur `content_html`), une clôture
  Markdown. Une réponse tronquée reste une erreur. Les deux appels perdus du
  laboratoire passent désormais : recherche 0/5 → 5/5.
- **Plafond de sortie.** 8 000 jetons ne suffisent pas pour 2 800 mots : la
  mesure sur quinze articles réels donne 2,0 jetons par mot au mieux et 3,97 sur
  un modèle qui facture son raisonnement. Le plafond se déduit désormais du
  nombre de mots visé (`MSRWA_Cost::output_budget`), et l'estimation de coût
  utilise la même fourchette mesurée au lieu de 1,6 jeton par mot.
- `quality_max_words` passe de 2 400 à 3 600 : la valeur livrée était inférieure
  au minimum exigé de 2 800.

## Version 0.2.49

- Les prompts deviennent des gabarits compilés depuis les réglages (`MSRWA_Prompt`) : nombre de mots, découpe en une ou deux pages, titre d’ouverture de la page 2, plan de sections modifiable, tailles et format d’images, langue du site, nombre de questions FAQ et limite de liens internes. Modifier un réglage modifie le prompt, il n’est plus réécrit à la main.
- Le laboratoire compile les gabarits avec la même classe que le moteur : ce qui est validé en laboratoire est exactement ce que le plugin enverra.
- Nouveaux réglages : `site_language`, `article_page2_heading`, `required_sections`, `facebook_collage_steps`.
- Laboratoire multi-fournisseurs : adaptateurs OpenAI, Gemini et Anthropic, table des tarifs publiés par fournisseur et trois paliers de modèles (bas, moyen, haut) pour comparer qualité, durée et coût sur le même prompt.
- Prompts rédigés en anglais pour une sortie en français, et complétés : recherche, recette canonique, article, relecture, vérification des faits, correction orthographique, images mise en avant et Facebook.

## Version 0.2.48

- Prompt article remplacé par une version prouvée en laboratoire : article complet en un seul appel, deux pages séparées par `<!--nextpage-->`, plan imposé en douze sections, 2800 mots minimum, champs SEO et légende Facebook dans la même réponse. Validé 10 contrôles sur 10 sur deux cuisines différentes.
- Correction d’un défaut de rédaction : le prompt précédent produisait un français sans accents. La densité d’accents passe de 1,0 à 33 pour mille caractères.
- `quality_min_words` porté à 2800 pour que la grille qualité corresponde au contrat rédactionnel.
- Laboratoire de prompts (`tools/`) : exécution d’une étape contre l’API réelle sans WordPress, notation par la grille qualité du plugin, génération d’images de contrôle, coût et durée relevés à chaque essai.

## Version 0.2.47

- Moteur de coût dérivé (`MSRWA_Cost`) : chaque étape est estimée en tokens puis tarifée au prix catalogue du modèle qu’elle utiliserait. Plus aucune constante forfaitaire.
- Estimation en quatre postes — Article, Image mise en avant, Image Facebook, Autres — avec un minimum (tout passe du premier coup) et un maximum (toutes les corrections sont consommées) par poste et par recette.
- Les API d’image facturant l’image produite, le catalogue porte désormais le nombre de tokens générés par taille et par qualité, modifiable par l’administrateur, multiplié par le tarif de sortie du modèle. Une combinaison inconnue est signalée comme inconnue, jamais comme gratuite.
- La taille d’image estimée suit le ratio configuré, via la même table que la génération.

## Version 0.2.46

- Sécurité : toutes les réponses de l’API du plugin sont marquées non stockables et privées. Un cache de page traite une requête authentifiée par mot de passe d’application comme une requête visiteur et pouvait servir la réponse à tout le monde ; constaté en test réel sur un hébergement LiteSpeed qui servait le catalogue en cache à un appelant anonyme.
- Couche de tests réels : exécution depuis un poste distant via l’API REST et un mot de passe d’application, sans accès shell. `php tests/real/run.php` vérifie les prérequis, puis contrôle sur le site en ligne que le plugin est actif, que ses routes répondent, que le catalogue est tarifé et qu’aucune route n’est ouverte au public.

## Version 0.2.45

- Recherche externe de secours supprimée.
- Relecture IA du contenu et du réalisme des deux images, avec verdicts textuels distincts.
- Objectifs de longueur contrôlés séparément et conservés pour les corrections ; minimum par défaut de 2 000 mots, maximum de 2 400 mots, configurables. Nouvelle vérification à la création du brouillon.
- Routage automatique limité aux fournisseurs connectés et aux capacités prises en charge.

## Version 0.2.44

- Configuration dédiée à la pagination en deux pages et aux choix indépendants des images principale et Facebook.
- Choix conservés dans les données de chaque nouveau job ; étapes de génération et de contrôle ignorées pour les images désactivées.
- Facebook sans image principale utilise la recette comme base de génération.

## Version 0.2.43

- Tests de régression sur la planification : un lot continue de se vider au-delà de la limite de simultanéité, un bail expiré libère son créneau, et un job sans créneau est replanifié. La correction 0.2.42 est désormais protégée.

## Version 0.2.42

- Correction majeure de la file : un lot plus grand que la limite de traitements simultanés s’arrêtait après la première vague, les jobs restants n’étant jamais replanifiés. Les créneaux libérés sont désormais repris à chaque fin de job, et un worker sans créneau replanifie son job au lieu de l’abandonner.
- Un bail de worker expiré ne bloque plus un créneau jusqu’au nettoyage quotidien.

## Version 0.2.41

- Actions par job directement dans la liste : relance, annulation et confirmation d’association, avec les mêmes garde-fous que le détail du lot.
- Avertissement de file d’attente sur l’écran de création lorsque des traitements sont interrompus ou que la planification WordPress ne tourne plus ; détail réservé aux administrateurs.
- Statistiques alignées sur le verdict de livraison : taux de contrôles réussis par article, distinct du taux de conformité structurelle, et score moyen lu sur le verdict enregistré.
- Suite de tests hors ligne outillée : `php tests/run.php` (lint + tests), harnais partagé `tests/bootstrap.php`, double `$wpdb` enregistreur, intégration continue PHP 7.4/8.1/8.3.
- Documentation de reprise dans le dépôt : `CLAUDE.md`, `docs/ARCHITECTURE.md`, `docs/ROADMAP.md`, `docs/TESTING.md`.

## Version 0.2.40

- Liste **Articles** complète : pagination, recherche par titre ou numéro de job, filtres publication (brouillon, publié, en attente, planifié, privé, corbeille, brouillon supprimé), qualité, état, période, tri et nombre par page.
- Liste **Jobs** complète : mêmes filtres plus l’étape du pipeline, et un dépliant par ligne réunissant diagnostic technique, erreur, article lié, modèles retenus, corrections par élément, appels, tokens, coûts, fenêtres d’exécution et propriétaire.
- Cloisonnement par éditeur appliqué côté requête : un paramètre d’auteur forgé est ignoré pour qui ne possède pas `msrwa_view_all`. Le filtre Auteur n’est proposé qu’aux administrateurs.
- Verdict qualité persisté sur le job (`quality_score`, `quality_passed`, `quality_checked_at`), écrit à la création du brouillon et rétro-rempli une seule fois à la migration.
- Corrections : le total d’un lot correspond désormais aux jobs réellement créés, un lot sans job est signalé en erreur au lieu de rester bloqué ; la reprise de données structurées ne se rejoue plus à chaque mise à jour ; les écrans de diagnostic bornent leurs requêtes.
- Interface : barre de filtres et pagination dédiées, pastilles de statut de publication, libellés d’état en français, onglet actif annoncé aux lecteurs d’écran.

## Version 0.2.39

- Qualité rattachée à l’article produit : un job sans article n’affiche plus de verdict, et la moyenne d’un lot porte sur ses articles.
- Page de création en deux onglets : liste des articles par défaut, avec ouverture du brouillon et détail du job, puis onglet Jobs pour les jobs et les lots.
- Statistiques qualité calculées sur les articles produits : nombre d’articles, score de structure moyen, conformité, mots moyens et articles sous coût cible.

## Version 0.2.38

- Liens internes contextuels dans les paragraphes uniquement : suppression de la section automatique de recommandations et de son réglage de titre.
- Consignes d’ancres configurables ; conservation du texte et des liens existants, sans lien imbriqué ni cible externe.

## Version 0.2.37

- État simplifié (`encours`, `completed`, `canceled`, `error`) distinct de la qualité (verdict et score de structure en %). `completed` indique la fin du traitement, pas une validation éditoriale. Les pauses et actions requises restent signalées ; une réponse fournisseur incertaine ne vaut pas réussite.
- Sauvegarde du contenu disponible en brouillon pour les jobs à vérifier, échoués, suspendus pour budget ou à résultat fournisseur incertain ; aucune publication automatique.
- Rapport privé de relecture dans le détail du job et l’éditeur : score de structure, observations éditoriales et images manquantes. Un score élevé ne vaut pas validation culinaire.
- Reprise sans doublon et protection des articles qui ne sont plus des brouillons.
- Division en deux pages activable, avec respect des limites HTML ; maintien du contenu non divisé lorsqu’un brouillon partiel est trop court.
- Corrections bornées par élément, préservation des images remplacées et diagnostics API sans secrets ni données binaires.
- Requêtes de recherche bornées et comptabilisées ; suspension après un résultat texte incertain pour éviter une relance payante automatique.
- Conservation du collage Facebook complet avec ajustement configurable et mesure des durées des appels images.

Les coûts affichés sont des estimations calculées avec le catalogue configuré, non une facture fournisseur. Un appel interrompu peut avoir été facturé. La validation éditoriale et les tests complets des fournisseurs restent nécessaires avant utilisation en production.

## Installation locale

1. Installer le dossier dans `wp-content/plugins/ms-recipes-writer-ai/`.
2. Activer **MS Recipes Writer AI**.
3. Ouvrir **MS Recipes Writer → Configuration**.
4. Configurer prompts, modèles, tarifs, budgets opérationnels et clés serveur. Une clé seule ne déclenche aucun job.
5. Utiliser **Créer des Articles/Images** avec une recette ou un brief, puis ajouter des références visuelles si nécessaire.

Les tables sont préfixées par la base WordPress et sont créées à l’activation. La désactivation retire uniquement la planification du plugin ; elle ne supprime pas les données ni les médias.

## Documentation de conception

Le plan complet et les critères d’acceptation se trouvent dans [`../MS-Recipes-Writer-AI-PLAN-FINAL.md`](../MS-Recipes-Writer-AI-PLAN-FINAL.md).
