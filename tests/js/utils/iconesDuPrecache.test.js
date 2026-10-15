import { afterEach, describe, expect, it } from 'vitest'
import { createHash } from 'node:crypto'
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { iconesDuPrecache } from '@/sw/iconesDuPrecache'

/*
 * Les icônes de public/ entrent au precache avec l'empreinte de leur contenu
 * (#2048) : le glob du precache ne lit que public/build, et aucune ne venait
 * sans réseau.
 */
describe('iconesDuPrecache', () => {
    let dossier

    afterEach(() => {
        rmSync(dossier, { recursive: true, force: true })
    })

    const publicFactice = (fichiers) => {
        dossier = mkdtempSync(join(tmpdir(), 'public-'))

        for (const [nom, contenu] of Object.entries(fichiers)) {
            writeFileSync(join(dossier, nom), contenu)
        }

        return dossier
    }

    it('prend les icônes de la racine, à leur adresse, triées, avec l’empreinte de leur contenu', () => {
        const racine = publicFactice({ 'logo.svg': '<svg/>', 'favicon.ico': 'ico', 'pwa-64x64.png': 'png' })

        expect(iconesDuPrecache(racine)).toEqual([
            { url: '/favicon.ico', revision: createHash('sha256').update('ico').digest('hex') },
            { url: '/logo.svg', revision: createHash('sha256').update('<svg/>').digest('hex') },
            { url: '/pwa-64x64.png', revision: createHash('sha256').update('png').digest('hex') },
        ])
    })

    it('laisse le reste de public/ : scripts, textes, manifeste et dossiers', () => {
        const racine = publicFactice({
            'index.php': '<?php',
            'robots.txt': 'User-agent: *',
            'manifest.webmanifest': '{}',
            'badge.svg': '<svg/>',
        })
        mkdirSync(join(racine, 'build.png'))

        expect(iconesDuPrecache(racine).map((entree) => entree.url)).toEqual(['/badge.svg'])
    })

    it('change de révision quand l’icône change', () => {
        const avant = iconesDuPrecache(publicFactice({ 'logo.svg': '<svg>a</svg>' }))[0].revision
        rmSync(dossier, { recursive: true, force: true })
        const apres = iconesDuPrecache(publicFactice({ 'logo.svg': '<svg>b</svg>' }))[0].revision

        expect(apres).not.toBe(avant)
    })
})
