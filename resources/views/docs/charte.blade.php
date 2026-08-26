{{--
    La page de documentation de la charte.

    Elle est ÉCRITE ici et non construite en PHP : ce gabarit faisait 138 lignes
    de HTML dans une méthode, ce que PHP Insights a signalé à juste titre. Le
    framework a un moteur de gabarit ; s'en passer pour concaténer des chaînes
    perd l'échappement automatique, l'indentation lisible, et rend la page
    impossible à relire.

    Les données viennent de `App\Console\Commands\PublierLaCharte`, qui les lit
    dans `resources/css/app.css`. Aucune couleur n'est écrite ici.
--}}
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Charte couleur GymTracker</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:ital,wght@0,700;0,900;1,900&family=Space+Grotesk:wght@400;500;700&display=swap">
<style>
:root {
  --page: #f8faff; --carte: #ffffff;
  --encre: #0f172a; --attenue: #627188; --trait: #e2e8f0;
  --orange: #ff5500; --orange-lisible: #b63c00; --ok: #08724f;
  --mono: ui-monospace, SFMono-Regular, Menlo, monospace;
}
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    --page: #0b1020; --carte: #131a2e;
    --encre: #eef2ff; --attenue: #9aa6c4; --trait: #263050;
    --orange-lisible: #ff7a3d; --ok: #34d399;
  }
}
:root[data-theme="dark"] {
  --page: #0b1020; --carte: #131a2e;
  --encre: #eef2ff; --attenue: #9aa6c4; --trait: #263050;
  --orange-lisible: #ff7a3d; --ok: #34d399;
}
* { box-sizing: border-box; }
body { margin:0; padding:0 1.25rem 5rem; background:var(--page); color:var(--encre);
  font-family:'Space Grotesk',ui-sans-serif,system-ui,sans-serif; line-height:1.6; }
.enveloppe { max-width:68rem; margin:0 auto; }
header { padding:4rem 0 2.5rem; border-bottom:2px solid var(--encre); }
.eyebrow { font-size:.7rem; font-weight:700; letter-spacing:.22em; text-transform:uppercase;
  color:var(--orange-lisible); margin:0 0 .75rem; }
h1 { font-family:Archivo,sans-serif; font-weight:900; font-style:italic; text-transform:uppercase;
  font-size:clamp(2.4rem,8vw,4.2rem); line-height:.95; letter-spacing:-.03em; margin:0; text-wrap:balance; }
.chapeau { margin:1.25rem 0 0; max-width:46rem; color:var(--attenue); font-size:1.05rem; }
.compteurs { display:flex; flex-wrap:wrap; gap:2.5rem; margin:2rem 0 0; }
.compteur strong { display:block; font-family:Archivo,sans-serif; font-weight:900;
  font-size:2.4rem; line-height:1; font-variant-numeric:tabular-nums; }
.compteur span { font-size:.72rem; letter-spacing:.14em; text-transform:uppercase; color:var(--attenue); }
h2 { font-family:Archivo,sans-serif; font-weight:900; font-style:italic; text-transform:uppercase;
  font-size:1.6rem; letter-spacing:-.02em; margin:4rem 0 .5rem; text-wrap:balance; }
h2 + .intro { margin:0 0 2rem; color:var(--attenue); max-width:46rem; }
.regle { margin:2.5rem 0 0; padding:1.5rem 1.75rem; background:var(--carte);
  border:1px solid var(--trait); border-left:4px solid var(--orange); border-radius:0 1rem 1rem 0; }
.regle p { margin:0; } .regle p + p { margin-top:.75rem; color:var(--attenue); }
.famille { margin:2.5rem 0; }
.famille h3 { font-family:Archivo,sans-serif; font-weight:700; text-transform:uppercase;
  letter-spacing:.1em; font-size:.82rem; margin:0 0 .25rem; color:var(--orange-lisible); }
.famille .sous { margin:0 0 1rem; color:var(--attenue); font-size:.92rem; max-width:46rem; }
.grille { display:grid; grid-template-columns:repeat(auto-fill,minmax(15rem,1fr)); gap:.75rem; }
.jeton { display:flex; align-items:center; gap:.85rem; background:var(--carte);
  border:1px solid var(--trait); border-radius:.85rem; padding:.6rem .75rem; }
.puce { width:2.9rem; height:2.9rem; flex:0 0 auto; border-radius:.6rem; display:grid;
  place-items:center; box-shadow:inset 0 0 0 1px rgb(0 0 0 / .08); }
.puce span { font-family:Archivo,sans-serif; font-weight:900; font-size:.95rem; }
.jeton-txt { display:flex; flex-direction:column; min-width:0; gap:.1rem; }
.jeton-txt code { font-family:var(--mono); font-size:.78rem; }
.hex { font-family:var(--mono); font-size:.7rem; color:var(--attenue); text-transform:uppercase; }
.mesure { font-size:.68rem; color:var(--attenue); font-variant-numeric:tabular-nums; }
.tableau { overflow-x:auto; border:1px solid var(--trait); border-radius:1rem; background:var(--carte); }
table { width:100%; border-collapse:collapse; font-size:.88rem; }
th { text-align:left; padding:.8rem 1rem; font-size:.68rem; letter-spacing:.14em; text-transform:uppercase;
  color:var(--attenue); border-bottom:1px solid var(--trait); font-weight:700; }
td { padding:.6rem 1rem; border-bottom:1px solid var(--trait); vertical-align:middle; }
tr:last-child td { border-bottom:0; }
td code { font-family:var(--mono); font-size:.78rem; }
code.petit { font-size:.72rem; color:var(--attenue); }
.apercu { display:inline-block; padding:.28rem .7rem; border-radius:.45rem; font-family:Archivo,sans-serif;
  font-weight:900; font-size:.68rem; text-transform:uppercase; letter-spacing:.06em; white-space:nowrap; }
.num { font-variant-numeric:tabular-nums; font-family:var(--mono); font-size:.8rem; white-space:nowrap; }
.num.ok { color:var(--ok); }
footer { margin-top:4rem; padding-top:1.5rem; border-top:1px solid var(--trait);
  color:var(--attenue); font-size:.82rem; }
</style>
</head>
<body>
<div class="enveloppe">
<header>
  <p class="eyebrow">GymTracker · charte graphique</p>
  <h1>Un composant<br>nomme un rôle</h1>
  <p class="chapeau">
    Aucune couleur n'est écrite en dehors de <code>resources/css/app.css</code>. Un composant y nomme
    ce qu'il veut dire — un accent, un danger, une catégorie — et n'a jamais à savoir de quelle
    couleur il s'agit.
  </p>
  <div class="compteurs">
    <div class="compteur"><strong>{{ $jetons }}</strong><span>jetons déclarés</span></div>
    <div class="compteur"><strong>{{ $apparies }}</strong><span>surfaces appariées</span></div>
    <div class="compteur"><strong>{{ $gardes }}</strong><span>gardes de convention</span></div>
  </div>
</header>

<div class="regle">
  <p><strong>La règle tient en une phrase :</strong> un composant nomme un rôle, jamais une couleur.</p>
  <p>
    Avant, la même couleur s'écrivait de quatre façons — un alias, un rôle, un hexadécimal en dur
    dans un graphique, ou une nuance de Tailwind — et le choix entre elles était un hasard
    d'écriture. C'est ce qui a rendu le mode sombre irréparable.
  </p>
</div>

<h2>Les jetons</h2>
<p class="intro">
  Chaque pastille montre la couleur, son nom, sa valeur, et le meilleur texte qu'elle peut porter —
  mesuré à la génération, pas estimé.
</p>
@foreach ($familles as $famille)
  <section class="famille">
    <h3>{{ $famille['titre'] }}</h3>
    <p class="sous">{{ $famille['sous'] }}</p>
    <div class="grille">
      @foreach ($famille['jetons'] as $jeton)
        <div class="jeton">
          <div class="puce" style="background:{{ $jeton['valeur'] }}">
            <span style="color:{{ $jeton['encre'] }}">Aa</span>
          </div>
          <div class="jeton-txt">
            <code>{{ $jeton['nom'] }}</code>
            <span class="hex">{{ $jeton['valeur'] }}</span>
            <span class="mesure">{{ $jeton['porte'] }} · {{ $jeton['mesure'] }}:1</span>
          </div>
        </div>
      @endforeach
    </div>
  </section>
@endforeach

<h2>Les surfaces appariées</h2>
<p class="intro">
  Un composant ne choisit pas la couleur du texte qu'il pose sur un fond : il écrit <strong>un</strong>
  nom et reçoit les deux. Un jeton de texte seul ne peut pas suffire — l'orange porte du blanc à
  4,7:1 et de l'encre à 3,8:1, le vert d'état exactement l'inverse. Aucune valeur unique ne convient
  aux deux, et demander au composant de trancher, c'est lui rendre la décision que la charte lui retire.
</p>
<div class="tableau">
<table>
  <thead><tr><th>Utilitaire</th><th>Rendu</th><th>Fond</th><th>Texte</th><th>Mesure</th></tr></thead>
  <tbody>@foreach ($apparieesDetail as $paire)
        <tr>
          <td><code>{{ $paire['nom'] }}</code></td>
          <td><span class="apercu" style="background:{{ $paire['fondHex'] }};color:{{ $paire['texteHex'] }}">Terminer</span></td>
          <td><code class="petit">{{ $paire['fond'] }}</code></td>
          <td><code class="petit">{{ $paire['texte'] }}</code></td>
          <td class="num ok">{{ $paire['mesure'] }}:1</td>
        </tr>
      @endforeach</tbody>
</table>
</div>

<footer>
  Page générée par <code>php artisan charte:publier</code> depuis <code>resources/css/app.css</code>.
  Les contrastes sont recalculés à la génération : ils ne peuvent pas diverger de la charte, et
  <code>LaPageDeLaCharteEstAJourTest</code> refuse une page qui aurait pris du retard.
</footer>
</div>
</body>
</html>

