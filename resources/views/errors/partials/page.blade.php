{{--
    Une page d'erreur doit tenir SEULE.

    Celle-ci chargeait Tailwind depuis `cdn.tailwindcss.com`. Deux problemes, et
    le second explique pourquoi personne ne l'a jamais vu :

     - la CSP de cette application declare `script-src 'self'`. Le CDN etait donc
       BLOQUE en production, et cette page y a toujours ete rendue sans aucun
       style. En local la politique ajoute `unsafe-inline`, alors elle
       s'affichait correctement sur la machine de celui qui la relisait ;
     - une page d'erreur qui depend d'un serveur tiers depend de quelque chose de
       plus au moment precis ou quelque chose vient de casser.

    Elle n'a donc plus ni script, ni feuille externe, ni manifeste Vite a
    resoudre : un seul bloc `<style>`, autorise par `style-src 'unsafe-inline'`
    dans les deux environnements. Les couleurs viennent de `App\Support\Charte`,
    qui lit `app.css` — le rendu contient bien des litteraux, c'est inevitable
    pour une page autonome, mais ils ne sont ecrits qu'a un endroit.
--}}
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $titre }}</title>
    <style>
        :root {
            --page: {{ \App\Support\Charte::jeton('surface-page') }};
            --carte: {{ \App\Support\Charte::jeton('surface-card') }};
            --encre: {{ \App\Support\Charte::jeton('text-main') }};
            --attenue: {{ \App\Support\Charte::jeton('text-muted') }};
            --trait: {{ \App\Support\Charte::jeton('border') }};
            --accent: {{ \App\Support\Charte::jeton('accent-primary') }};
            --accent-plein: {{ \App\Support\Charte::jeton('accent-primary-fill') }};
            --sur-accent: {{ \App\Support\Charte::jeton('text-on-dark-accent') }};
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: var(--page);
            color: var(--encre);
            font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
            text-align: center;
        }

        .carte {
            background: var(--carte);
            border: 1px solid var(--trait);
            border-radius: 1.5rem;
            padding: 3rem 2rem;
            max-width: 28rem;
            width: 100%;
        }

        .code {
            font-size: clamp(3.5rem, 18vw, 5rem);
            font-weight: 900;
            line-height: 1;
            letter-spacing: -0.04em;
            margin: 0;
            color: var(--accent);
        }

        .titre {
            font-size: 1.25rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            margin: 1rem 0 0;
        }

        .detail {
            margin: 0.75rem 0 0;
            color: var(--attenue);
            line-height: 1.6;
        }

        .retour {
            display: inline-block;
            margin-top: 2rem;
            padding: 0.85rem 1.75rem;
            border-radius: 0.75rem;
            background: var(--accent-plein);
            color: var(--sur-accent);
            font-weight: 700;
            text-decoration: none;
        }

        .retour:hover {
            opacity: 0.9;
        }

        .retour:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 3px;
        }
    </style>
</head>

<body>
    <main class="carte">
        <p class="code">{{ $code }}</p>
        <h1 class="titre">{{ $titre }}</h1>
        <p class="detail">{{ $detail }}</p>
        <a class="retour" href="{{ url('/') }}">Retour à l'accueil</a>
    </main>
</body>

</html>
