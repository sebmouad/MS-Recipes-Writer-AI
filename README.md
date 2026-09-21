# MS Recipes Writer AI

Plugin WordPress en construction pour la génération éditoriale culinaire orchestrée.

## État actuel

La version `0.2.84` est un socle installable : file persistante, pipeline de
génération, contrôle qualité déterministe, budgets, images et écrans
d’administration. Le détail des fonctionnalités livrées se trouve dans
l’historique des versions ci-dessous.

Le plugin est en cours de refonte éditoriale et budgétaire :

- [`docs/PLAN.md`](docs/PLAN.md) — ce qui est construit, en clair.
- [`docs/BUILD-CHECKLIST.md`](docs/BUILD-CHECKLIST.md) — les tâches à réaliser,
  leurs tests et leur critère d’achèvement.
- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — le code tel qu’il existe.
- [`docs/ENGINE.md`](docs/ENGINE.md) — le contrat du moteur : comment on
  l’appelle, comment on le configure, ce qu’il rend.
- [`docs/TESTING.md`](docs/TESTING.md) — suite hors ligne et tests réels.

Les coûts affichés sont des estimations calculées avec le catalogue configuré,
non une facture fournisseur. `completed` signifie que le traitement est terminé,
jamais qu’un texte est validé éditorialement.

## Version 0.2.84

**Retrait du saut de relecture introduit en 0.2.83, qui ne se déclenchait
jamais.** L'idée tenait : la relecture est l'étape la plus chère du groupe de
vérification — 0,0104 $ et 35 s, environ 7 000 tokens de sortie — et elle
réécrivait l'article entier même quand la revue n'avait rien à redire.

Rejoué sur les cinq runs complets enregistrés, le saut ne se serait déclenché
aucune fois : la revue relève entre deux et cinq remarques à chaque article,
sans exception. Le garde était juste, ses tests aussi, mais c'était une porte
qui ne s'ouvre jamais. Le moteur ne garde pas de code qui ne fait rien.

L'économie annoncée n'existait pas. Ce qui reste de l'épisode est une mesure qui
vaut mieux que le code : la revue n'a jamais approuvé un article sans réserve,
et savoir si c'est un signal honnête ou un prompt qui doit toujours trouver
quelque chose se vérifie sans rien dépenser.

## Version 0.2.83

**Saut de relecture sur article propre** — ajouté puis retiré en 0.2.84, faute
de se déclencher une seule fois sur les runs mesurés.

## Version 0.2.82

**Les photographies citées sont lues ensemble, plus l'une après l'autre.** La
phase d'observation téléchargeait une image, la décrivait, puis passait à la
suivante : trois inconnus dont on attendait la latence bout à bout, 19,4
secondes sur un run mesuré. Les photographies n'ont aucun rapport entre elles,
et le moteur savait déjà lancer plusieurs appels de front — `http_many()`
existait pour les vagues et n'était pas utilisé ici.

Deux vagues concurrentes remplacent six appels en file. Mesuré sur les mêmes
références, avec le même instruction et les mêmes observations en sortie : 16,8
s en série contre 8,9 s ensemble, pour 0,0021 $ contre 0,0020 $. Les
vérifications restent inchangées et appliquées image par image — HTTPS, adresse
publique, corps borné, type réel — et un téléchargement refusé n'empêche plus
les autres.

## Version 0.2.81

**Le juge reçoit enfin les observations visuelles qu'on lui promettait.** Son
prompt annonçait « le dossier de recherche, avec les observations tirées de
photographies réelles de ce plat » — et `research_for_text()` retirait
précisément celles-là. Il décidait si une photographie ressemble à ce plat sans
avoir jamais vu à quoi ce plat ressemble, sinon par les quelques mots de couleur
et de texture glissés dans le brief visuel.

Il reçoit maintenant, explicitement : la **recette canonique de l'étape 2**,
qu'il prend comme référence, et les **observations visuelles de l'étape 1**,
lues dans les octets des vraies photographies.

**Le générateur et le juge reçoivent l'inverse l'un de l'autre, exprès.** Le
générateur dessine ce qu'on lui montre : lui montrer la garniture d'un autre
cuisinier, c'est la voir apparaître — filigrane, mains, poivrons, carottes,
quatre images perdues. Le juge, lui, compare : il doit la voir. Chaque
observation lui arrive donc avec son **rang** (ce plat, ou une variante, preuve
plus faible), sa **source**, et ce que la passe visuelle **n'a pas su
identifier**.

Et une règle qui compte autant que l'évidence elle-même : **une photographie
n'ajoute jamais une exigence.** Une assiette source servie avec du riz ne rend
pas le riz obligatoire. L'évidence sert à reconnaître le plat et à juger si la
cuisson est crédible, jamais à réclamer un élément que la recette canonique ne
contient pas.

Vérifié en réel sur le poulet yassa : image à la une `good`, collage `good`,
cohérence `good` — et **aucun constat ne réclame un élément vu sur une
photographie source**. Un contrôle hors ligne vérifie les deux sens : que le
juge reçoit l'inventaire du cadre, et que le générateur ne le reçoit toujours
pas.

## Version 0.2.80

**Le juge prend la recette canonique comme donnée** (décision du propriétaire,
2026-09-21). C'est la contradiction F14 de l'audit, tranchée.

L'étape recette reçoit l'ordre d'être cuisinable : « la complétude prime sur la
prudence », un ingrédient de base que la méthode exige manifestement — matière
grasse, sel, poivre, eau ou bouillon, le liquide d'une marinade — figure dans la
liste même si aucune source n'en donne la quantité, parce qu'une étape qui dit
de saisir ou d'assaisonner en a besoin. Le juge, lui, bloquait « un ingrédient
que nul fait de recherche n'appuie ». Un rédacteur obéissant était donc refusé
par un juge obéissant.

Désormais : **le silence de la recherche n'est pas un constat contre la
recette ; une contradiction l'est.** Restent bloquants — un ingrédient, une
étape, une température ou une durée que la recherche contredit directement ; un
ingrédient que la recherche tient pour essentiel et que la recette omet ; et une
affirmation de l'**article** qui ne se trouve ni dans la recette ni dans la
recherche.

Vérifié en réel sur le poulet yassa, dont la recette canonique porte six
ingrédients qu'aucune source ne chiffre :

| | avant | après |
|---|---|---|
| huile, sel, poivre, eau, bouillon non sourcés | bloquants | **aucun constat** |
| autorité du juge | la recherche | **la recette canonique** |
| image à la une | — | `good` |

Les refus restants sont justes : l'article ajoutait du persil absent de la
recette, et un panneau du collage montrait les hauts de cuisse crus déjà dans le
liquide. Le juge cite maintenant la recette canonique comme référence, et non le
silence des sources.

## Version 0.2.79

Premier lot de corrections issues de l'audit externe du 2026-09-21. Chaque
constat a été **reproduit** avant d'être corrigé ; le script de régression que
l'audit fournissait sans l'avoir exécuté échouait bien sur ses quatre
assertions, et il entre dans la suite.

**F01 — la porte d'acceptation (P0).** `decide()` vérifiait trois des contrats
qu'il venait de noter et lisait `approved` avec `!empty()`. La chaîne `"false"`
est vraie en PHP : elle approuvait. Et le contrôle « une approbation et un
constat bloquant ne tiennent pas ensemble » était calculé puis ignoré. La
décision est désormais dans `MSRWA_Engine_Score::accepts()`, testable seule :
booléen `true` strict, zéro constat bloquant, et tous les contrats structurels —
dont le réalisme de chaque image et la validité des cibles. Vérifié sur les neuf
verdicts réels du dépôt : **aucune décision ne change**.

**F03 — les redessins comptés deux fois.** Une image régénérée était
enregistrée comme étape *et* rajoutée au coût de l'approbation. Prouvé sur un
passage réel : $0,0281 comptés deux fois, **22 % de surcoût affiché**. Le
passage complet de la 0.2.78 a réellement coûté **$0,1437**, pas $0,1747.

**F05 — un tarif inconnu devenait zéro.** `(float) null` vaut `0.0` : un
passage sur un modèle sans tarif publié s'affichait gratuit et franchissait le
budget. Le coût inconnu reste inconnu, `totals()` compte les étapes non
tarifées, et le budget s'arrête plutôt que de valider ce qu'il ne peut pas
vérifier.

**F11 — des identifiants pouvaient entrer dans les rapports.** Un appelant
pointant vers sa propre passerelle peut écrire un jeton dans un en-tête ou dans
l'URL. Les en-têtes sensibles, les `user:pass@` et les paramètres de clé sont
masqués avant toute sérialisation et sur chaque événement d'appel.

**F12 — les passages sauvegardés n'étaient pas déballés.** `--canonical=<run>.json`
transmettait l'enveloppe entière — `ok`, `steps`, `totals`, `events` — à la
place de la recette. Le chargeur extrait l'artefact nommé et refuse un passage
qui ne le contient pas.

**F35 — le rapport rendait du HTML de modèle tel quel.** L'article était
concaténé dans la page. Il est reconstruit depuis une liste blanche : les
balises dont une recette a besoin, aucun attribut sauf un `href` de schéma sûr.
Vérifié : `<script>`, `<iframe>`, `onclick`, `onerror`, `javascript:` disparaissent ;
titres, gras, listes, tableaux, liens et le marqueur de page survivent.

Reste ouvert et demandant un arbitrage : le siège de test du transport, F02,
F04, F06, F07 (admission stricte), et les deux contradictions de prompts
F14/F15.

## Version 0.2.78

**Les étapes indépendantes partent ensemble.** Le moteur savait déjà lesquelles
ne s'attendent pas — c'est ce que déclare `needs` — mais il les appelait l'une
après l'autre. Ce qu'il y gagnait n'était que de l'attente.

| Vague | avant | après |
|---|---:|---:|
| article + image à la une + collage | 137,9 s | **43,4 s** |
| revue + fact-check | 65,0 s | **21,8 s** |
| **passage complet** | **436 s** | **290 s** (−33 %) |

Seule la première tentative est partagée. Une étape qu'il faut redemander l'est
seule : à ce moment-là elle ne fait plus la même chose que les autres.

Le transport se sépare en deux : `plan_text()`, `plan_image()`, `plan_judge()`
préparent un appel, `read()` en lit la réponse. Un échec dans un lot reste un
échec de cet appel-là et ne dérange pas les autres.

**Le cache de prompts : mesuré, et inexploitable ici.** Un appel identique est
mis en cache à 100 %. Le *même préfixe de 6 700 jetons avec une fin différente*
ne l'est pas du tout — 0 %. Sur ce point d'accès le cache porte sur l'entrée
entière, pas sur son préfixe : réorganiser le pipeline pour partager un préfixe
n'aurait rien rapporté. `store: false` n'y est pour rien, contrairement à
l'hypothèse de départ.

**Une réponse qui n'analyse pas est conservée.** C'était le seul échec qu'un
passage ne savait pas expliquer après coup : le barème disait « non analysable »
et le texte était jeté. Le moteur en garde 2 000 caractères et dit comment il
commence.

Cela s'est payé immédiatement : la revue s'arrêtait pile sur son plafond de
3 000 jetons, coupée au milieu de son JSON, facturée en entier, notée 0/3.
**Sixième plafond de cette liste trouvé de cette façon** — porté à 6 000.

**Et une régression trouvée par un nouveau contrôle.** En déplaçant un bloc,
`MSRWA_Engine::observe()` a disparu avec lui. La suite hors ligne est restée
verte ; la panne est sortie en erreur fatale au milieu d'un passage réel, après
que l'appel a été payé. Un contrôle lit désormais le source du moteur et échoue
si une méthode appelée n'existe pas.

Passage complet vérifié : **approuvé**, les quatre verdicts `good`, aucun
constat, 290 s, $0,1747.

## Version 0.2.77

**Les images refusaient toujours pour la même raison, et ce n'était pas le
prompt.** Chaque défaut mesuré venait de la même source : la passe visuelle
inventoriait ce qu'il y avait *dans la photographie* au lieu de décrire *le
plat*, et ces phrases voyageaient jusqu'au prompt image comme des faits sur le
plat. Le modèle les dessinait.

| Ce qui a été dessiné | D'où cela venait |
|---|---|
| « La Cuisine de Biscottine » et une bordure jaune | le filigrane et le cadre d'une photo source |
| le plat tenu à deux mains | la mise en scène de la photo source |
| des poivrons, une tranche de citron | « lanières rouges et vertes », « un demi-fruit jaune » |
| des carottes | « morceaux orange visibles » |

Cinq défauts, cinq refus, **un seul canal** : `observable_details` et
`composition`. Ce sont des inventaires du cadre. `colours` et `textures`
décrivent la nourriture. Le prompt image ne reçoit plus que ces deux-là.

Trois corrections, à trois niveaux :

1. **À la source.** La passe visuelle décrit le plat et rien d'autre : ni
   accompagnement, ni garniture, ni seconde assiette, ni couverts, ni mains, ni
   personne, ni vêtement, ni fond — et jamais le texte, le filigrane, le logo ou
   la bordure posés sur l'image. Ce qu'elle ne peut pas identifier part dans
   `uncertainties`, au lieu d'être décrit par sa forme et sa couleur.
2. **En défense.** Un filtre phrase par phrase retire ce qui parle de la
   photographie plutôt que du plat. Une mauvaise proposition ne coûte plus toute
   l'observation.
3. **En dernier recours.** Six règles courtes closent chaque prompt image — là
   où un modèle pèse le plus — et chacune est une règle déjà énoncée plus haut
   qu'une génération réelle a quand même enfreinte.

**Mesuré, même recette, même dossier de recherche** (le poulet yassa, refusé
trois fois de suite sur ses images) :

| | avant | après |
|---|---|---|
| image à la une | `bad` | **`good`** |
| collage | `bad` | `reservations` |
| cohérence | `bad` | **`good`** |
| constats bloquants | 3 | **0** |

**Et le prompt maigrit de 31 %** — 34 104 → 23 678 caractères pour la paire.
Un appel image est facturé surtout sur ce qu'on lui envoie : l'entrée
représentait 51 % du coût de l'image à la une et 69 % de celui du collage. La
paire passe de $0,0598 à $0,0535. Le vrai gain reste le cycle de refus évité :
$0,0615 et 75 secondes.

Le dossier brut ne suit plus le brief visuel qui le distillait déjà : c'était
38 % du prompt de l'image à la une, en doublons — et cela doublait aussi le
poids des mauvaises phrases.

## Version 0.2.76

**Rien n'est figé dans le moteur.** Points d'accès des fournisseurs, tarifs des
modèles, paliers, registre des étapes, prompts, chaque seuil de notation et
chaque plafond : tout est une clé de configuration, et l'appelant peut remplacer
n'importe laquelle sans toucher à celles d'à côté.

| Clé | Ce qu'elle décide |
|---|---|
| `providers` | points d'accès, en-têtes, variables de clé, outil de recherche web |
| `models` | tarif par million de jetons, `[entrée, sortie]` |
| `tiers` | ce que `fournisseur:low\|medium\|high` désigne |
| `steps` | libellé, poste de budget, dépendances, gabarit de prompt |
| `prompts` | le texte du prompt, à la place du gabarit livré |
| `thresholds` | chaque nombre auquel un contrôle se compare |
| `limits` | budget, délai HTTP, taille d'image, photographies analysées |

La surcharge est chirurgicale : changer le point d'accès d'un fournisseur
conserve ses en-têtes, recharger une étape sur un autre poste conserve ses
dépendances, déplacer un seuil laisse les autres à leur valeur mesurée. Un
appelant peut aussi **ajouter** une étape que le moteur n'a jamais eue ; elle
prend part à l'ordonnancement par vagues comme les autres.

`MSRWA_Engine_Rates` disparaît : les tarifs et les paliers sont dans la
configuration, donc le laboratoire et le plugin ne peuvent plus diverger.
`tests/test-engine-configurable.php` lit le code du moteur et échoue si une URL
ou un nom de variable de clé y réapparaît.

**Le moteur rend compte de ce qu'il fait des données, pas seulement de ce que
cela coûte.** Trois nouveaux types d'événement :

- `config` — quelles clés l'appelant a surchargées, et la provenance complète :
  `engine`, `caller` ou `run`, pour chacune.
- `input` — d'où vient le prompt, sa taille, la taille de l'entrée, le plafond de
  sortie, quels artefacts étaient attachés, si la recherche web était active.
- `call` — quel point d'accès a répondu, sur quel modèle et quel palier, les
  jetons dans chaque sens, **combien le fournisseur a servis depuis son cache**,
  le prix, le statut d'arrêt.

Chaque étape enregistre aussi le prompt exécuté et d'où il venait.

**Le rapport montre tout cela** : la recette canonique se lit enfin *comme une
recette* — c'est la référence à laquelle tout se mesure, elle méritait mieux
qu'un vidage clé/valeur — plus une ligne par appel fournisseur, ce que chaque
étape a reçu, et la configuration effective avec la couche qui a décidé chaque
clé.

**Un bug trouvé par ce nouveau relevé.** Les noms de modèles contiennent des
points, et la lecture par chemin pointé découpait dessus :
`models.openai.gpt-5.6-luna` ne résolvait rien, et un passage complet se
rapportait comme gratuit. Sans la ligne « tarif inconnu » par appel, un total à
$0,00 serait passé pour une bonne nouvelle. Corrigé, et un contrôle vérifie
désormais que **chaque modèle livré et chaque palier se tarifient**.

## Version 0.2.75

**« Relancer jusqu'à approbation » est mesuré, pas supposé.** Sur les mêmes
artefacts du poulet yassa : premier verdict *refusé* — des poivrons et une
tranche de citron sur la photographie principale, absents de la recette ; trois
feuilles de laurier là où elle en prévoit une. Les deux images sont redessinées
avec ces constats portés dans le prompt comme corrections, et le second verdict
**approuve**, ne laissant que trois constats mineurs sur la présentation, à
l'appréciation de l'éditeur.

$0,1414 et 78 s pour la boucle, contre $0,0075 pour un verdict qui approuve du
premier coup. Ce qui compte : les ingrédients inventés ne sont pas revenus. Une
correction n'est pas un second coup de dé.

Les deux régénérations sont enregistrées comme leurs propres étapes, chacune
avec son coût dans son propre poste de budget, et chaque image conserve les
corrections dont elle est née.

Mesures reportées dans [`docs/LAB-RESULTS.md`](docs/LAB-RESULTS.md).

## Version 0.2.74

**Le juge ne recevait pas l'article.** `MSRWA_Engine_Input::build()` n'avait
aucune branche pour l'approbation finale : elle retournait le prompt seul. Le
juge voyait les deux images et rien d'autre — ni article, ni recette, ni
recherche — et refusait en le disant : « L'article complet n'a pas été fourni ».

Un passage complet sur le poulet yassa l'a montré : l'approbation a refusé trois
fois de suite, au tarif plein, sans qu'aucun contrôle hors ligne ne puisse le
voir. Corrigé et vérifié sur les mêmes artefacts : **10/10**, article jugé
*conforme*, et le refus porte désormais sur ce qu'il doit — des poivrons et une
tranche de citron absents de la recette, trois feuilles de laurier là où elle en
prévoit une.

**Un refus que le moteur ne peut pas corriger n'est plus réessayé.** Quand le
verdict est sain, refusé, et qu'aucune image n'est mise en cause, reposer la
même question au même juge sur les mêmes artefacts est un second coup de dé.
C'est une décision, pas un essai raté : elle part à l'éditeur. Cela coûtait deux
appels par passage.

Un contrôle hors ligne vérifie maintenant que chaque étape reçoit ce à quoi elle
est mesurée — recette, recherche, article — et il échoue bien si on retire la
branche.

### Premier passage complet du nouveau moteur

Poulet yassa, dix étapes, bout en bout :

| | |
|---|---|
| temps | 346,7 s |
| coût | $0,1274 |
| jetons | 120 217 entrée · 32 028 sortie |
| texte / image à la une / collage / recherche | $0,0370 · $0,0278 · $0,0350 · $0,0277 |

Recherche 14/14 avec deux photographies réelles lues dans leurs octets, recette
4/4, article 10/10, revue 3/3, fact-check 5/5, corrections 2/2, langue 6/6.

## Version 0.2.73

**Huit outils deviennent un.** `tools/lab.php` est la seule commande ; le reste
est le moteur.

| Avant | Maintenant |
|---|---|
| `prompt-lab.php` | `lab.php step <étape>` |
| `image-lab.php` | `lab.php step featured_image\|facebook_image` |
| `approval-lab.php` | `lab.php step final_approval` |
| `judge-stability.php` | `lab.php judge --draws=5` |
| `build-standalone-report.php` | `lab.php report` |
| `promote-prompts.php` | `lab.php prompts` |
| `prune-runs.php` | `lab.php prune` |
| `format-cost.php` | `tools/experiments/` — sa question est tranchée |
| — | `lab.php run` : la recette entière, en un appel |

**Le rapport HTML lit une exécution, plus treize fichiers.** Il fallait nommer
treize chemins sur la ligne de commande, ce qui permettait d'assembler un
rapport à partir de passages n'ayant jamais appartenu à la même recette. Il part
désormais d'un `MSRWA_Result` et de rien d'autre.

Ce qu'il montre en plus : le coût par poste de budget, le nombre d'essais par
étape, chaque vérification avec ce qu'elle a mesuré, les corrections réellement
appliquées au texte, et le déroulé de l'exécution — vagues, reprises,
avertissements, échecs. La décision d'approbation passe en bandeau au-dessus des
quatre verdicts : elle est leur somme, pas leur égale.

**Deux trous du moteur, trouvés en retirant les outils.** L'analyse des
photographies citées et celle des images fournies par l'éditeur ne vivaient que
dans `prompt-lab.php`. Une exécution déclenchée par le plugin aurait rendu une
recherche sans aucune observation visuelle — et tous les prompts suivants
s'appuient dessus. Les deux passes sont dans le moteur, facturées à l'étape de
recherche, à laquelle elles appartiennent.

`tools/lib/` passe de 157 à 133 lignes et ne fait plus qu'une chose : lire une
fiche d'essai ou un passage sauvegardé sur le disque. Le registre d'étapes que
le laboratoire tenait en double a disparu ; il n'y en a qu'un, celui du moteur.

## Version 0.2.72

**La boucle de correction se referme.** La revue et le fact-check produisaient
des constats depuis des semaines et rien ne les appliquait — en réalité je les
appliquais à la main, dans le générateur de rapport, codés en dur par recette.

Le fact-check cite déjà mot pour mot la phrase qu'il conteste et fournit celle
qui la remplace ; son propre barème vérifie que la citation est bien dans
l'article. L'appliquer est donc une substitution, pas un jugement : aucun modèle
ne tourne, cela ne coûte rien, et rien ne peut être inventé.

La nouvelle étape `corrections` enregistre les deux moitiés : ce qu'elle a
appliqué, et ce qu'elle n'a pas su localiser dans le HTML — le fact-check répond
en phrases simples alors que l'article est du HTML, donc une phrase coupée par
une balise est un cas réel. Ce reste part à l'éditeur au lieu de disparaître.

Un constat de revue ne porte pas de citation : c'est un avis sur une section.
Aucun n'est appliqué ici ; ils accompagnent le reste jusqu'à l'éditeur.

La correction de langue tourne désormais sur le texte déjà corrigé sur le fond,
pour la même raison que l'approbation était passée derrière la relecture :
corriger la langue d'un paragraphe sur le point d'être remplacé est du travail
perdu.

| Vague | Étapes simultanées |
|---|---|
| 1 | recherche |
| 2 | recette canonique |
| 3 | **article, image à la une, collage** |
| 4 | **revue, fact-check** |
| 5 | corrections factuelles (aucun modèle) |
| 6 | correction de langue |
| 7 | approbation finale |

## Version 0.2.71

Le moteur a une porte d'entrée : `MSRWA_Engine::run()`.

Un appel, un brief, un `MSRWA_Result` : les artefacts produits, le détail par
étape, les totaux dans les quatre postes de budget, les erreurs et les
événements tels qu'ils se sont produits. Rien ne lève d'exception et rien ne
sort du processus — un plugin ne peut se le permettre en pleine requête — donc
un échec est une valeur, enregistrée à côté de ce qui a réussi.

- Les étapes tournent par vagues de dépendances : l'article s'écrit pendant que
  les deux images se dessinent, puis les trois relectures tournent ensemble.
- Trois chemins d'appel selon ce que l'étape demande au modèle : texte, génération
  d'image, et le juge qui lit les octets des deux images à la fois.
- « Relancer jusqu'à approbation » converge : un refus régénère **les images que
  le juge a bloquées**, avec ses constats comme corrections, avant de redemander
  un verdict — au lieu de relancer le même dé.
- Le budget arrête le passage avant l'étape qui le dépasserait.
- Le moteur ne lit plus rien sur le disque : les artefacts arrivent en mémoire.
  Charger un passage sauvegardé est le travail du laboratoire, pas du moteur.
- La configuration parle **le vocabulaire du moteur** : c'est l'appelant qui
  traduit le sien, jamais l'inverse. Le moteur est la pièce réutilisée ; il ne
  peut pas porter une table de correspondance par appelant.

## Version 0.2.70

Deuxième étape du déplacement : **tout le comportement est dans le moteur**.

| Classe | Rôle | Lignes |
|---|---|---:|
| `MSRWA_Engine_Config` | valeurs par défaut, réglages du plugin, demandes d'un passage | 195 |
| `MSRWA_Engine_Steps` | ce qu'exige chaque étape, donc l'ordre et le parallélisme | 98 |
| `MSRWA_Engine_Input` | ce qu'une étape reçoit avant de tourner | 420 |
| `MSRWA_Engine_Score` | ce qui compte comme réussi, vérifié sur les données rendues | 278 |
| `MSRWA_Engine_Call` | tous les appels fournisseurs, normalisés | 277 |
| `MSRWA_Engine_Rates` | tarifs publiés et paliers | 72 |
| `MSRWA_Result` | l'enveloppe rendue, et le rapport d'avancement | 107 |

`tools/lib/steps.php` passe de **762 à 157 lignes** : il ne reste que ce qui est
propre au laboratoire — amorçage des doubles de test, lecture des réglages
livrés, registre pour la ligne de commande — et des adaptateurs vers le moteur.

**Les prompts déménagent dans `includes/engine/prompts/`.** Ce sont les données
du moteur : il les exécute, il les porte. Les fiches d'essai restent côté
laboratoire, qui indique sa racine au moteur.

**La configuration se résout en trois couches**, chacune écrasant la
précédente : les valeurs par défaut du moteur, les réglages du plugin traduits
dans le vocabulaire du moteur, puis ce que demande ce passage précis. Le
résultat effectif est consigné sur l'exécution — clés d'API exclues — pour qu'un
rapport puisse répondre des mois plus tard à « avec quoi cela a-t-il tourné ».

Une valeur écrite à la main est ramenée dans ce que les fournisseurs acceptent
réellement, et une clé inconnue est ignorée plutôt que refusée : un plugin plus
ancien et un moteur plus récent continuent de fonctionner ensemble.

## Version 0.2.69

**Le laboratoire devient le moteur du plugin** — première étape. Le code
d'orchestration quitte `tools/` pour `includes/engine/`, chargé par le plugin
comme par le laboratoire. C'est le même code des deux côtés, par construction :
un prompt prouvé au banc d'essai ne peut plus diverger de celui qui tourne en
production.

Le moteur ne touche aucune fonction WordPress. Le plugin lui passe ses réglages
et récupère un résultat.

- **`MSRWA_Result`** — l'enveloppe que rend chaque exécution : `ok`, artefacts,
  étapes, totaux, erreurs, événements. Elle sert de rapport d'avancement pendant
  l'exécution *et* de trace après, si bien que l'écran d'administration et le
  rapport HTML lisent la même chose. Un observateur reçoit chaque événement au
  moment où il se produit, pour une barre de progression réelle.
- **Une panne est une valeur, jamais une mort.** Clé absente, modèle inconnu,
  fournisseur en erreur : tout revient en donnée. Le laboratoire pouvait se
  permettre `exit(2)` ; un plugin ne le peut pas. Les neuf points de sortie
  brutale ont disparu.
- **`MSRWA_Engine_Steps`** décrit ce que chaque étape exige avant de tourner. Le
  graphe qui en découle montre où se gagne le temps :

| Vague | Étapes simultanées |
|---|---|
| 1 | recherche |
| 2 | recette canonique |
| 3 | **article, image à la une, collage** |
| 4 | **revue, fact-check, correction** |
| 5 | approbation finale |

  Les images ne dépendent que de la recette et de la recherche, pas de
  l'article : elles peuvent tourner pendant sa rédaction. Chemin critique estimé
  à ~186 s contre ~250 s en série.

- **Correction éditoriale trouvée en dessinant le graphe** : l'approbation
  dépend désormais de la correction, pas de l'article brut. On jugeait le texte
  avant sa relecture, donc pas celui que le lecteur reçoit.

`tools/lib/` ne contient plus que des adaptateurs vers le moteur, le temps de
migrer les appelants ; ils disparaîtront à l'étape suivante.

## Version 0.2.68

**`recipe_outline` ne laisse plus de trou.** Le contrat exigeait sept valeurs
mais tolérait qu'elles disparaissent : sur le poulet yassa, la recherche n'en
rendait que deux sur quatre. Les clés sont désormais **toujours présentes**, le
modèle doit chercher chaque figure avant d'y renoncer — une page de recette
donne presque toujours un nombre de parts et un temps de cuisson, et le total
est la somme des deux quand les deux existent — et `null` ne s'écrit que pour une
valeur réellement cherchée qu'aucune source ne donne.

Vérifié sur les deux plats : poulet yassa **14/14**, `cook_minutes` à `null`
honnêtement plutôt qu'omis ; souris d'agneau **14/14**, les huit clés présentes
et les quatre figures trouvées.

**L'approche fusionnée est retirée.** Le gabarit, l'étape de laboratoire, son
score et ses tirages quittent le dépôt. La mesure qui l'a écartée reste dans
l'historique des versions et dans `docs/BUILD-CHECKLIST.md` (A32) : 1,3 %
d'économie pour 13 secondes de plus, et un échec de validation cinq fois plus
cher à rejouer.

## Version 0.2.67

Le gabarit fusionné rejeté quitte `tools/prompts/` pour `tools/experiments/`.
Il restait dans le jeu actif et faisait échouer deux contrôles qui comptent les
prompts livrés — et j'avais poussé la 0.2.66 sans les relancer, ce qui est
exactement la règle que ce dépôt s'impose.

`tools/experiments/README.md` conserve la mesure, la raison du rejet et la
commande pour la rejouer. Le laboratoire sait toujours exécuter l'expérience :
une réponse mesurée doit survivre à la question.

## Version 0.2.66

**Fusionner recherche et recette canonique : mesuré, puis écarté.**

La question était légitime — la recherche rend déjà les ingrédients avec leurs
quantités et les étapes dans l'ordre, soit presque la recette. Le gabarit fusionné
`research_recipe.tpl.txt` fait les deux travaux en un appel et il **fonctionne** :
17/17 sur le poulet yassa, recette valide, 10 ingrédients, 12 étapes.

Il ne rapporte simplement rien. Sur le même plat :

| | Temps | Coût | Score |
|---|---:|---:|---|
| Recherche + recette, séparées | 49,5 s + 21,8 s = **71,3 s** | 0,0183 $ + 0,0047 $ = **0,0230 $** | 13/14 puis 4/4 |
| Fusionnées | **84,5 s** | **0,0227 $** | 17/17 |

Soit 1,3 % d'économie — dans le bruit — pour **13 secondes de plus**. La sortie
d'un seul appel se génère séquentiellement : 8 635 jetons d'affilée coûtent plus
de temps que 6 943 puis 3 162 dans deux appels.

Trois raisons de garder la séparation, au-delà des chiffres :

1. **Le coût d'un échec.** Une recette rejetée par le validateur se rejoue
   aujourd'hui pour 0,0047 $. Fusionnée, il faudrait relancer l'appel avec
   recherche web : 0,0227 $, soit **cinq fois plus**.
2. **Deux métiers différents.** La recherche consigne ce que disent les sources,
   désaccords compris ; la recette tranche. Un seul appel doit faire les deux, et
   le risque est qu'il tranche en silence au lieu de consigner le désaccord.
3. **Le cache ne joue pas en sa faveur.** Les reprises d'un appel identique sont
   mises en cache à 99,96 % ; plus l'appel rejoué est gros, plus on paie la part
   non mise en cache.

Le gabarit reste dans le dépôt, comme `article_full` avant lui : une expérience
mesurée et rejetée vaut mieux qu'une question rouverte tous les trois mois.

Trouvé au passage : la recherche seule n'a donné que 2 des 4 figures de
`recipe_outline` sur ce plat, là où la version fusionnée en donne 4 — le contrat
d'`outline` mérite d'être resserré.

## Version 0.2.65

**La recherche devient le socle réel du pipeline.** Elle ne rend plus des faits
épars mais la recette telle que les sources la décrivent, sourcée ligne à ligne :

- `recipe_outline` — portions, temps de préparation, de cuisson, total, catégorie,
  cuisine, difficulté ;
- `ingredients` — nom, quantité, unité, **rôle dans le plat**, caractère
  **essentiel**, et sa source ;
- `preparation` — étapes numérotées dans l'ordre réellement exécuté, chacune avec
  son **repère visuel de fin** et, quand la source les donne, minutes et
  température ;
- `substitutions`, `accompaniments`, `common_failures`, `storage` — uniquement ce
  qu'une source atteste.

**Les images sont désormais hiérarchisées.** Rang 1 : de vraies photographies de
*ce* plat, trois sur trois domaines différents. Rang 2 : jusqu'à deux
photographies d'une variante proche ou du composant caractéristique, réservées à
la direction visuelle quand le rang 1 est mince. Chaque image porte son rang, si
bien qu'une étape ultérieure sait le poids à lui accorder.

Mesuré sur la souris d'agneau : **14/14**, 10 ingrédients tous sourcés, 10 étapes
portant toutes leur repère, 3 références rang 1. `research_max_output_tokens`
passe de 7 000 à 12 000 — le paquet plus riche dépassait l'ancien plafond.

### Format d'échange et cache : mesurés, pas supposés

`tools/format-cost.php` compare plusieurs rendus du même paquet et lit les jetons
*facturés par le fournisseur*, pas une estimation.

| Format | Caractères | Jetons | Coût |
|---|---:|---:|---:|
| enregistrements « pipe » | 9 406 | **2 461** | 0,00053 $ |
| JSON compact (actuel) | 9 649 | 2 500 | 0,00054 $ |
| lignes indentées | 9 781 | 2 609 | 0,00056 $ |
| JSON indenté | 11 448 | 2 871 | 0,00062 $ |

**Changer de format ne vaut pas la peine : 1,6 %.** Le gain réel avait déjà été
pris en abandonnant le JSON indenté (−13 %). Le tokeniseur digère très bien la
ponctuation JSON, et nous perdrions un format que tout le monde sait lire.

**Le cache, lui, est massif — mais seulement sur les appels identiques.** Un même
prompt rejoué : **6 825 jetons sur 6 828 mis en cache**, soit 99,96 %. Deux
recettes différentes partageant 1 622 jetons de gabarit : **0**. Conséquence
directe et utile : les reprises — la relance d'un jugement mal formé, la
régénération après refus — ne coûtent presque rien en entrée. La stratégie
« réessayer jusqu'à approbation » que vous avez choisie est donc moins chère que
le prix affiché par appel ne le laisse croire.

## Version 0.2.64

Le juge a été mesuré au lieu d'être supposé : mêmes artefacts, même prompt,
plusieurs passages par fournisseur (`tools/judge-stability.php`).

| Fournisseur | Verdicts exploitables | Accord entre eux | Coût / appel |
|---|---:|---:|---:|
| `gpt-5.6-luna` | 4/5 | 4/4 | **0,0061 $** |
| `claude-sonnet-5` | 3/3 | 3/3 | 0,153 $ |
| `gemini-3.5-flash` | 3/5 | 2/3 | 0,017 $ |

**Deux faux positifs systématiques trouvés et corrigés.** Sur des artefacts
identiques, le juge n'approuvait que 2 fois sur 5. Les refus portaient sur des
brins verts « ressemblant à du persil » — alors que le thym, le romarin et le
laurier figurent dans cette recette — et sur **un verre de vin posé à côté de
l'assiette**, lu comme un ingrédient inventé. Le contrôle ne juge désormais que
ce qui est *dans* le plat : une boisson, une bouteille, un bol à côté, des
couverts ne sont jamais un ingrédient ; et avant de déclarer un ajout, le juge
doit chercher dans la liste ce que l'élément pourrait être, les herbes hachées
étant indiscernables à cette résolution. Résultat immédiat : de 2/5 à **4/4**
d'accord.

**`approval_max_output_tokens` passe de 6 000 à 14 000** — le cinquième plafond
trop bas de ce projet. Claude rédige des verdicts nettement plus longs que
OpenAI et se faisait couper systématiquement.

**Deux corrections à ce que j'avais annoncé :**

- J'avais écrit « environ un appel sur trois mal formé ». Le chiffre datait
  d'avant la clarification du contrat de sortie ; la mesure donne **1 sur 5**, et
  la boucle redemande le jugement dans ce cas.
- J'avais conclu « Claude ne sait pas juger » sur un 0/5. C'était le plafond, pas
  le modèle : à 14 000 jetons il rend **3/3** verdicts exploitables et cohérents.
  Il reste 25 fois plus cher qu'OpenAI pour ce travail.

Gemini ignore par ailleurs la consigne de ne pas compter les objets : son seul
constat bloquant reprochait « 5 souris d'agneau » dans un panneau.

**A14 est close, autrement que prévu.** Sonnet 5 ne tronque plus l'article
(11 698 jetons sous le plafond, `end_turn`) : le plafond corrigé et l'élagage des
prompts ont suffi. Mais il écrit désormais trop **court** — 2 420 mots pour
2 800 exigés — à 0,1396 $ contre 0,0092 $ pour OpenAI. Le diagnostic initial,
« il écrit beaucoup trop long », était faux.

## Version 0.2.63

**Le juge traite désormais l'absence et l'invention de façon opposée**
(directive du 21/09), et c'est la bonne asymétrie.

- **Ce qui manque — indulgent.** Seuls les ingrédients *principaux* doivent être
  visibles. Tout autre ingrédient peut être invisible sans que ce soit un
  constat : il peut être dissous dans la sauce, enfoui sous une couche, déjà
  absorbé ou hors cadre. Le comptage reste ignoré.
- **Ce qui est ajouté — strict.** Tout élément comestible visible et absent de la
  liste canonique est **bloquant**, si discret soit-il : un brin de romarin, une
  feuille de laurier, un piment, un quartier de citron, une cuillerée de crème.
  Un lecteur qui suit la recette ne produira pas cette assiette.
- **Le doute penche vers le blocage**, à l'inverse du reste du contrôle : un
  ingrédient inventé trompe celui qui cuisine.

Mesuré sur deux plats. Souris d'agneau : des morceaux de tomate absents de la
recette — bloqué, corrigé, approuvé en un tirage (0,0721 $). Poulet yassa : une
douzaine de morceaux orange lus comme des carottes alors que la recette ne
contient qu'**un** habanero — bloqué, corrigé, approuvé en un tirage (0,0734 $).
La reprise a remplacé la douzaine par un seul habanero entier, exactement ce que
la liste prévoit.

**Un verdict mal formé n'est plus pris pour un refus.** `gpt-5.6-luna` ferme
parfois l'objet racine trop tôt et laisse les verdicts d'images en dehors, ce
qui se lisait comme « refusé, aucun constat » — et déclenchait une régénération
d'images contre une décision que personne n'avait prise. Le laboratoire
redemande le jugement sans toucher aux images. Observé sur environ un appel sur
trois ; à surveiller.

## Version 0.2.62

**Les neuf prompts sont désormais des gabarits compilés depuis les réglages**,
et plus aucun ne code en dur ce qu'un réglage contrôle. La langue de sortie,
jusque-là écrite « French » dans cinq prompts, vient de `site_language` :
basculer le site en espagnol bascule la recherche, la recette, l'article, la
relecture, la correction, les deux images et le contrôle final.

- `tools/promote-prompts.php` compile chaque gabarit et l'écrit dans les
  réglages qui alimentent la table `prompts`.
- `tests/test-prompt-templates.php` vérifie qu'aucun placeholder ne survit,
  qu'aucune langue n'est codée en dur, et surtout **que ce qui est livré est
  exactement ce que le gabarit compile**. La dérive entre le laboratoire et la
  production devient impossible : le plugin ne peut plus exécuter un prompt que
  personne n'a mesuré.

**Les fiches d'essai portent enfin de vraies observations.** Quatre des cinq
citaient `example.test` : aucune photographie n'avait jamais été analysée pour
elles, et toute mesure d'apparence portait sur une amorce inventée. Les cinq
sont rejouées sur de vraies sources — `provenceweb.fr`, `ptitchef.com`,
`commons.wikimedia.org`, `thekitchn.com`, `normandie-tourisme.fr` — avec 1 à 3
photographies téléchargées et analysées octet par octet.

Trouvé en chemin : le CDN de Food Network refuse nos requêtes (HTTP 403), et une
seule référence citée suffisait alors à nous laisser sans aucune observation. La
recherche doit maintenant citer **trois** photographies sur **trois domaines
différents** ; un refus ne rend plus aveugle. Nous ne contournons pas ce refus :
un serveur qui dit non a dit non.

## Version 0.2.61

L'intégration continue ne teste plus PHP 7.4. La matrice se limite à PHP 8.1 et
8.3, et les deux documents qui annonçaient encore 7.4 sont corrigés : un dépôt
ne doit pas revendiquer une compatibilité qu'il ne vérifie plus.

Le rapport HTML a par ailleurs été revu :

- **Sections dépliables.** Photographies analysées, dossier de recherche,
  recette, métadonnées SEO, relecture et données brutes s'ouvrent à la demande.
  La page se lit court et se creuse où le lecteur veut.
- **Constats mineurs visibles.** Ils étaient noyés dans un bloc JSON ; ils
  forment maintenant des fiches au même titre que les bloquants, avec la phrase
  en cause, ce qui ne va pas et la correction proposée — ce sont précisément les
  arbitrages qui reviennent à l'éditeur.
- **Responsive corrigé.** Le tableau des étapes forçait une largeur minimale de
  760 px ; sur mobile il s'empile en fiches, chaque valeur portant son intitulé.
  Rendu vérifié dans un vrai navigateur avant envoi.
- Les verdicts s'affichent en français (`Conforme`, `Réserves`, `Non conforme`)
  au lieu des valeurs brutes du modèle.

## Version 0.2.60

Passage complet rejoué avec le code courant, **approuvé sans constat bloquant**,
pour 0,1137 $ et environ quatre minutes de temps fournisseur.

**Le rapport suit l'ordre d'exécution** et prouve la provenance : brief de
l'éditeur, photographies réelles trouvées et analysées, recherche, recette,
article, SEO, relecture, visuels, approbation. La section 2 liste chaque
photographie citée avec sa page source et son fichier, dit si elle a été
analysée, et montre ce que la vision en a lu. Aucune n'est republiée.

**Élagage des prompts, partout où il réduit le coût** (directive du 21/09) :

| Appel | Avant | Après |
|---|---:|---:|
| Collage Facebook | 33 091 car. — *refusé par le fournisseur* | 20 711 car. |
| Image à la une | 0,0427 $ | **0,0266 $** |
| Collage Facebook | 0,0443 $ | **0,0326 $** |
| Recette canonique | 22 792 car. | 16 614 car. |
| Article | 35 296 car. | 29 118 car. |

Le collage dépassait la limite de 32 000 caractères du fournisseur et échouait
en HTTP 400 : on envoyait tout le dossier de recherche à un modèle d'image, dont
des températures, des URL et des règles d'hygiène qui ne changent aucune photo.
Les étapes texte perdent de la même façon `originality_notes`,
`visual_references` et `visual_observations` — l'apparence leur arrive distillée
par le brief visuel. Gain réel : environ 27 % sur les images, où les jetons
d'entrée sont facturés 5 $/M.

**Trois défauts trouvés en chemin :**

- `research_max_output_tokens` livré à 4 000 tronquait la recherche dès qu'on lui
  demandait aussi les substitutions (5 567 jetons nécessaires). Quatrième
  plafond trop bas — le laboratoire affiche désormais `!! TRUNCATED` dès qu'une
  réponse s'arrête exactement sur son plafond.
- Le prompt image dépassait la limite du fournisseur ; il échoue maintenant chez
  nous, où la cause est lisible.
- L'article inventait des substitutions et des gestes qu'aucune source ne
  documente — cinq constats bloquants. Il ne peut plus proposer qu'un
  remplacement attesté, et doit écrire franchement que les sources n'en
  documentent aucun le cas échéant. Constats bloquants : **5 → 0**.

## Version 0.2.59

Le rapport omettait l'étape d'approbation finale — celle qui décide — et ses
libellés ne se rattachaient à aucune commande : « Relecture » et « Revue
qualité » pouvaient désigner l'une ou l'autre étape.

- Chaque ligne du tableau porte désormais le nom de l'étape qui l'a produite :
  `Correction du français (proofread)`, `Revue éditoriale (review)`,
  `Vérification des faits (fact_check)`. Le tableau se vérifie ligne à ligne
  contre les commandes exécutées.
- L'approbation finale apparaît en neuvième ligne et dans sa propre section :
  décision, verdict par artefact, réalisme jugé à part pour les images, et les
  constats.

## Version 0.2.58

Premier passage complet sur une recette inédite — **souris d'agneau au four à
chaleur tournante, oignons grelots et ail confit** — et premier passage avec de
vraies photographies analysées, pas des amorces.

| Étape | Temps | Coût | Résultat |
|---|---:|---:|---|
| Recherche (2 photos réelles analysées) | 39,8 s | 0,0144 $ | 10/10 |
| Recette canonique | 22 s | 0,0038 $ | 4/4 |
| Article 3 413 mots | 47 s | 0,0095 $ | 10/10, qualité 98/100 |
| Image mise en avant | 10 s | 0,0373 $ | — |
| Collage Facebook | 13 s | 0,0428 $ | — |
| Relecture, fact-check, correction | 62 s | 0,0171 $ | 3/3, 5/5, 6/6 |
| Approbation finale | 22 s | 0,0055 $ | **10/10 — APPROUVÉ** |

Sources réelles : `greatbritishchefs.com`, deux photographies téléchargées et
analysées octet par octet. La mise en avant reprend ce qu'elles montrent — bol,
os saillants, sauce brune et luisante.

**Le juge applique désormais deux barèmes** (directive du 21/09), et c'est
délibéré : une photographie est une impression, l'article est ce que le lecteur
cuisine.

- **Images** : le réalisme photographique est la porte ; seuls les ingrédients
  *principaux* décident si c'est le bon plat ; herbe, gousse épluchée, comptage,
  vaisselle et cadrage deviennent mineurs ; le doute se résout vers la
  publication. Quatre calibrages antérieurs avaient chacun dépassé la cible.
- **Recette et article** : tout ingrédient, toute étape, toute durée que la
  recherche ne documente pas est bloquant, de même que tout ce qui la contredit.
  Le doute se résout vers le refus. Aucun échange entre les deux : de belles
  images n'excusent pas un ingrédient non sourcé.

Mesuré immédiatement : sur le même passage, les images tombent à deux constats
mineurs tandis que l'article récolte sept constats bloquants — substitutions,
accompagnements et critères d'achat qu'aucune source ne documente. La section
« substitutions » étant exigée par le plan de l'article, la recherche doit
maintenant collecter les substitutions et les accompagnements attestés.

- `tools/prune-runs.php` applique la rétention convenue : dernier article,
  dernière recette, dernière image mise en avant, **tous** les collages.
- `tools/cost-history.jsonl` conserve **tout** l'historique de coût — 152 appels,
  5,18 $ mesurés — avant toute suppression. Les tirages se régénèrent, les
  mesures non.
- Les rapports générés quittent le dépôt (8,8 Mo d'images encodées) ;
  `tools/reports/` est ignoré.
- Trois défauts trouvés en chemin : un `editor_input` mal formé était transmis
  tel quel et la recherche répondait, à juste titre, qu'on ne lui donnait aucun
  plat ; le score de recherche validait un paquet à zéro fait ; une image
  régénérée n'enregistrait jamais son coût.

## Version 0.2.57

**Ce que la recherche a lu et ce qu'elle a vu alimentent désormais toutes les
étapes**, et sous une forme exploitable : un *brief visuel* dérivé de la recette
et des observations, à la place du JSON brut qu'on jetait au modèle.

Chaque ligne du brief existe parce qu'une image réelle a échoué dessus :

- **Comptes exacts** tirés des quantités (`1 rouleau`, `6 pommes`, `2 œufs`),
  bornés à la mise en place seule.
- **Ustensiles** tirés de `equipment` : le moule doit être le même partout ; une
  planche ou un saladier nécessaires à une étape restent permis, un support qui
  change la présentation finale ne l'est pas.
- **Échelle et cuisson** : portions et durée réelles, « pas plus foncé pour
  l'effet ».
- **Apparence observée** dans de vraies photographies.
- **Une seule présentation de service, nommée**, partagée par les deux images.

Mesuré sur la tarte normande, huit collages puis quatre paires :

| Défaut | Avant | Après |
|---|---|---|
| Panneaux dans le désordre | 3 refus sur 5 | **aucun** |
| Contenant différent entre les deux images | quasi systématique | **aucun** une fois la présentation nommée |
| Ustensiles étrangers | plusieurs | **aucun** |

Décrire le plat ne suffisait pas : deux appels lisant « entière, vue de trois
quarts » choisissaient encore l'un une assiette, l'autre un moule. Le contenant
devait être **nommé**, pas décrit.

Le compte d'ingrédients passe de bloquant à mineur : un lecteur prend les
quantités dans la liste, pas en comptant les pommes sur une photo — et les
modèles d'image ne comptent pas de façon fiable.

`lab_observed_appearance()` alimente la recette canonique, `lab_visual_brief()`
l'article, les deux images et le contrôle final.

**Limite connue.** Les observations des fiches d'essai sont des amorces
pointant vers `example.test` : aucune vraie photographie n'a jamais été
analysée pour elles. Les refus de couleur restants s'appuient donc sur une
référence d'une ligne, inventée. Prochaine étape : rejouer la recherche avec le
scraping d'images réel sur les deux fiches.

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
- Suite de tests hors ligne outillée : `php tests/run.php` (lint + tests), harnais partagé `tests/bootstrap.php`, double `$wpdb` enregistreur, intégration continue PHP 8.1/8.3.
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
