# Simulateur de devis — production

## Variables serveur

Configurer ces variables uniquement dans l'environnement privé du backend Symfony :

```dotenv
DEEPSEEK_API_KEY=replace_with_a_rotated_private_key
DEEPSEEK_MODEL=deepseek-chat
DEEPSEEK_QUOTE_MAX_TOKENS=800
DEEPSEEK_QUOTE_TEMPERATURE=0.3
```

La clé DeepSeek ne doit jamais être copiée dans le frontend Vue, un fichier `dist`, un commit Git ou une documentation. Toute clé partagée dans une conversation doit être révoquée avant le déploiement.

Vérifier également les variables existantes `CONTACT_RECIPIENT_EMAIL`, `MAILER_FROM_EMAIL`, `BREVO_API_KEY` et `CORS_ALLOW_ORIGIN`. Cette dernière doit autoriser exactement le domaine HTTPS du frontend. Si le backoffice utilise une session entre deux domaines distincts, vérifier en production les attributs `Secure` et `SameSite` du cookie de session.

## Déploiement

1. Sauvegarder la base portfolio et le dossier courant du backend.
2. Installer les dépendances avec le lockfile : `composer install --no-dev --optimize-autoloader`.
3. Vérifier les migrations en attente : `php bin/console doctrine:migrations:status --env=prod`.
4. Appliquer les migrations : `php bin/console doctrine:migrations:migrate --no-interaction --env=prod`.
5. Vider le cache : `php bin/console cache:clear --env=prod`.
6. Déployer le build frontend contenant l'URL publique du backend.

Les migrations `Version20260915140000` et `Version20260915143000` créent uniquement `portfolio_quote_estimates` et mettent à jour le JSON de la table portfolio `content`. Elles ne lisent ni ne modifient aucune table `massage_*`.

## Vérifications

1. Soumettre une estimation publique valide et contrôler la réponse `201`.
2. Confirmer que le montant vient du calculateur et que `aiSource` vaut `deepseek` ou `fallback`.
3. Simuler une clé absente ou une réponse DeepSeek invalide et confirmer que le montant reste disponible.
4. Vérifier qu'une sixième requête dans l'heure renvoie `429` sans appel DeepSeek.
5. Se connecter au backoffice, ouvrir l'estimation et modifier son statut.
6. Vérifier que le formulaire de contact libre fonctionne toujours.
