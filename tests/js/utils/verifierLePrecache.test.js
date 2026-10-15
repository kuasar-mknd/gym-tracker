import { afterEach, describe, expect, it } from 'vitest'
import { spawnSync } from 'node:child_process'
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import { jsRoot } from '../conventions/sourceFiles'

const racineDuDepot = resolve(jsRoot, '../..')
const SCRIPT = join(racineDuDepot, 'scripts/verifier-le-precache.mjs')

/*
 * `scripts/verifier-le-precache.mjs`, que la CI lance sur la construction puis
 * sur les actifs que les tests PHP téléchargent (#1683, #2048).
 *
 * L'étape des tests PHP n'acceptait que /build/ et le manifeste : les icônes de
 * la racine de public/, au precache depuis #2048, la faisaient échouer avant
 * Pest, quand `ServiceWorkerAssetsTest` acceptait tout fichier de public/. Les
 * deux tiennent désormais la même règle : un fichier de public/ servi tel quel,
 * jamais un script PHP.
 */
describe('la vérification du precache', () => {
    /** Le dépôt factice du cas, s'il en a bâti un : le cas de la CI n'en bâtit pas. */
    let dossier

    afterEach(() => {
        if (dossier !== undefined) {
            rmSync(dossier, { recursive: true, force: true })
            dossier = undefined
        }
    })

    /**
     * Un dépôt factice : `fichiers` sous public/, et un worker qui précache `urls`.
     *
     * @param {string[]} urls
     * @param {Record<string, string>} fichiers chemin sous public/ → contenu
     */
    const verifier = (urls, fichiers) => {
        dossier = mkdtempSync(join(tmpdir(), 'precache-'))

        for (const [chemin, contenu] of Object.entries(fichiers)) {
            mkdirSync(dirname(join(dossier, 'public', chemin)), { recursive: true })
            writeFileSync(join(dossier, 'public', chemin), contenu)
        }

        const manifeste = JSON.stringify(urls.map((url) => ({ revision: null, url })))
        writeFileSync(join(dossier, 'public/sw.js'), `precacheAndRoute(${manifeste})`)

        return spawnSync(process.execPath, [SCRIPT], { cwd: dossier, encoding: 'utf8' })
    }

    it('accepte les actifs de public/build et les icônes de la racine de public/', () => {
        const resultat = verifier(
            ['/build/assets/main-Ab12.js', '/build/hors-ligne.html', '/favicon.ico', '/logo.svg'],
            {
                'build/assets/main-Ab12.js': 'x',
                'build/hors-ligne.html': '<!DOCTYPE html>',
                'favicon.ico': 'ico',
                'logo.svg': '<svg/>',
            },
        )

        expect(resultat.stderr).toBe('')
        expect(resultat.status).toBe(0)
        expect(resultat.stdout).toContain('precache : 4 entrées')
    })

    it('refuse une entrée que le serveur ne sert pas, comme les /assets/ sans /build/ de #1683', () => {
        const resultat = verifier(['/build/assets/main-Ab12.js', '/assets/main-Ab12.js'], {
            'build/assets/main-Ab12.js': 'x',
        })

        expect(resultat.status).toBe(1)
        expect(resultat.stderr.split('\n')).toContain('/assets/main-Ab12.js')
        expect(resultat.stderr.split('\n')).not.toContain('/build/assets/main-Ab12.js')
    })

    it('refuse un script que PHP exécuterait, et un dossier', () => {
        const resultat = verifier(['/index.php', '/build'], { 'index.php': '<?php', 'build/a.js': 'x' })

        expect(resultat.status).toBe(1)
        expect(resultat.stderr.split('\n')).toEqual(expect.arrayContaining(['/index.php', '/build']))
    })

    it('refuse un precache qui dépasse la coquille', () => {
        const urls = Array.from({ length: 26 }, (_, i) => `/build/assets/morceau-${i}.js`)
        const resultat = verifier(urls, Object.fromEntries(urls.map((url) => [url.slice(1), 'x'])))

        expect(resultat.status).toBe(1)
        expect(resultat.stderr).toContain('dépasse la coquille')
    })

    it('est le script que lancent le job des actifs et celui des tests PHP', () => {
        const ci = readFileSync(join(racineDuDepot, '.github/workflows/ci.yml'), 'utf8')

        expect(ci).toMatch(/- name: Le precache reste une coquille\n\s+run: node scripts\/verifier-le-precache\.mjs\n/)
        expect(ci).toMatch(/- name: Verify Service Worker Precache\n\s+run: node scripts\/verifier-le-precache\.mjs\n/)
    })
})
