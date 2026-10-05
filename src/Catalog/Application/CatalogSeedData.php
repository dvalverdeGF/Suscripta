<?php

declare(strict_types=1);

namespace App\Catalog\Application;

/**
 * Catálogo global de categorías y proveedores.
 *
 * Es un punto de partida, no una lista cerrada: el pipeline aprende proveedores
 * nuevos del propio buzón (ARCHITECTURE.md §13.6) y el usuario puede añadir los
 * suyos. Sembrar aquí solo ahorra el primer reconocimiento.
 *
 * Los dominios son la señal más estable: sobreviven a cambios de asunto, de
 * plantilla y de dirección de envío.
 */
final class CatalogSeedData
{
    /**
     * Categorías de gasto recurrente. `slug` es estable; `name` es lo que ve el
     * usuario y puede traducirse sin romper nada.
     *
     * @return list<array{slug: string, name: string, color: string, icon: string}>
     */
    public static function categories(): array
    {
        return [
            ['slug' => 'software', 'name' => 'Software y SaaS', 'color' => '#6366f1', 'icon' => 'app'],
            ['slug' => 'hosting', 'name' => 'Hosting y dominios', 'color' => '#0ea5e9', 'icon' => 'server'],
            ['slug' => 'telecomunicaciones', 'name' => 'Telecomunicaciones', 'color' => '#14b8a6', 'icon' => 'signal'],
            ['slug' => 'seguros', 'name' => 'Seguros', 'color' => '#f59e0b', 'icon' => 'shield'],
            ['slug' => 'suministros', 'name' => 'Suministros', 'color' => '#ef4444', 'icon' => 'bolt'],
            ['slug' => 'marketing', 'name' => 'Marketing y publicidad', 'color' => '#ec4899', 'icon' => 'megaphone'],
            ['slug' => 'formacion', 'name' => 'Formación', 'color' => '#8b5cf6', 'icon' => 'book'],
            ['slug' => 'transporte', 'name' => 'Transporte y logística', 'color' => '#64748b', 'icon' => 'truck'],
            ['slug' => 'servicios-profesionales', 'name' => 'Servicios profesionales', 'color' => '#0891b2', 'icon' => 'briefcase'],
            ['slug' => 'otros', 'name' => 'Otros', 'color' => '#94a3b8', 'icon' => 'dots'],
        ];
    }

    /**
     * Proveedores conocidos: nombre, slug, categoría por defecto, web y los
     * dominios desde los que factura.
     *
     * Solo se incluyen dominios verificables a partir del propio dominio
     * corporativo del proveedor. No se inventan direcciones de envío concretas:
     * esas las aprende el sistema del buzón real.
     *
     * @return list<array{slug: string, name: string, category: string, website: string, domains: list<string>}>
     */
    public static function providers(): array
    {
        return [
            // --- Software y SaaS ---
            ['slug' => 'microsoft', 'name' => 'Microsoft', 'category' => 'software', 'website' => 'https://www.microsoft.com', 'domains' => ['microsoft.com', 'office.com', 'microsoftonline.com', 'office365.com']],
            ['slug' => 'google', 'name' => 'Google', 'category' => 'software', 'website' => 'https://workspace.google.com', 'domains' => ['google.com', 'googlemail.com', 'youtube.com']],
            ['slug' => 'apple', 'name' => 'Apple', 'category' => 'software', 'website' => 'https://www.apple.com', 'domains' => ['apple.com', 'icloud.com']],
            ['slug' => 'adobe', 'name' => 'Adobe', 'category' => 'software', 'website' => 'https://www.adobe.com', 'domains' => ['adobe.com']],
            ['slug' => 'github', 'name' => 'GitHub', 'category' => 'software', 'website' => 'https://github.com', 'domains' => ['github.com']],
            ['slug' => 'atlassian', 'name' => 'Atlassian', 'category' => 'software', 'website' => 'https://www.atlassian.com', 'domains' => ['atlassian.com', 'atlassian.net']],
            ['slug' => 'jetbrains', 'name' => 'JetBrains', 'category' => 'software', 'website' => 'https://www.jetbrains.com', 'domains' => ['jetbrains.com']],
            ['slug' => 'slack', 'name' => 'Slack', 'category' => 'software', 'website' => 'https://slack.com', 'domains' => ['slack.com']],
            ['slug' => 'notion', 'name' => 'Notion', 'category' => 'software', 'website' => 'https://www.notion.so', 'domains' => ['notion.so']],
            ['slug' => 'figma', 'name' => 'Figma', 'category' => 'software', 'website' => 'https://www.figma.com', 'domains' => ['figma.com']],
            ['slug' => 'canva', 'name' => 'Canva', 'category' => 'software', 'website' => 'https://www.canva.com', 'domains' => ['canva.com']],
            ['slug' => 'miro', 'name' => 'Miro', 'category' => 'software', 'website' => 'https://miro.com', 'domains' => ['miro.com']],
            ['slug' => 'loom', 'name' => 'Loom', 'category' => 'software', 'website' => 'https://www.loom.com', 'domains' => ['loom.com']],
            ['slug' => 'calendly', 'name' => 'Calendly', 'category' => 'software', 'website' => 'https://calendly.com', 'domains' => ['calendly.com']],
            ['slug' => 'zapier', 'name' => 'Zapier', 'category' => 'software', 'website' => 'https://zapier.com', 'domains' => ['zapier.com']],
            ['slug' => 'make', 'name' => 'Make', 'category' => 'software', 'website' => 'https://www.make.com', 'domains' => ['make.com', 'integromat.com']],
            ['slug' => 'airtable', 'name' => 'Airtable', 'category' => 'software', 'website' => 'https://airtable.com', 'domains' => ['airtable.com']],
            ['slug' => 'monday', 'name' => 'monday.com', 'category' => 'software', 'website' => 'https://monday.com', 'domains' => ['monday.com']],
            ['slug' => 'asana', 'name' => 'Asana', 'category' => 'software', 'website' => 'https://asana.com', 'domains' => ['asana.com']],
            ['slug' => 'clickup', 'name' => 'ClickUp', 'category' => 'software', 'website' => 'https://clickup.com', 'domains' => ['clickup.com']],
            ['slug' => 'basecamp', 'name' => 'Basecamp', 'category' => 'software', 'website' => 'https://basecamp.com', 'domains' => ['basecamp.com']],
            ['slug' => 'linear', 'name' => 'Linear', 'category' => 'software', 'website' => 'https://linear.app', 'domains' => ['linear.app']],
            ['slug' => 'dropbox', 'name' => 'Dropbox', 'category' => 'software', 'website' => 'https://www.dropbox.com', 'domains' => ['dropbox.com']],
            ['slug' => 'zoom', 'name' => 'Zoom', 'category' => 'software', 'website' => 'https://zoom.us', 'domains' => ['zoom.us']],
            ['slug' => 'zoho', 'name' => 'Zoho', 'category' => 'software', 'website' => 'https://www.zoho.com', 'domains' => ['zoho.com', 'zoho.eu']],
            ['slug' => 'odoo', 'name' => 'Odoo', 'category' => 'software', 'website' => 'https://www.odoo.com', 'domains' => ['odoo.com']],
            ['slug' => 'shopify', 'name' => 'Shopify', 'category' => 'software', 'website' => 'https://www.shopify.com', 'domains' => ['shopify.com']],
            ['slug' => 'woocommerce', 'name' => 'WooCommerce', 'category' => 'software', 'website' => 'https://woocommerce.com', 'domains' => ['woocommerce.com', 'automattic.com']],
            ['slug' => 'prestashop', 'name' => 'PrestaShop', 'category' => 'software', 'website' => 'https://www.prestashop.com', 'domains' => ['prestashop.com']],
            ['slug' => 'stripe', 'name' => 'Stripe', 'category' => 'software', 'website' => 'https://stripe.com', 'domains' => ['stripe.com']],
            ['slug' => 'sentry', 'name' => 'Sentry', 'category' => 'software', 'website' => 'https://sentry.io', 'domains' => ['sentry.io']],
            ['slug' => 'datadog', 'name' => 'Datadog', 'category' => 'software', 'website' => 'https://www.datadoghq.com', 'domains' => ['datadoghq.com']],
            ['slug' => 'new-relic', 'name' => 'New Relic', 'category' => 'software', 'website' => 'https://newrelic.com', 'domains' => ['newrelic.com']],
            ['slug' => 'cloudinary', 'name' => 'Cloudinary', 'category' => 'software', 'website' => 'https://cloudinary.com', 'domains' => ['cloudinary.com']],
            ['slug' => 'algolia', 'name' => 'Algolia', 'category' => 'software', 'website' => 'https://www.algolia.com', 'domains' => ['algolia.com']],
            ['slug' => 'mapbox', 'name' => 'Mapbox', 'category' => 'software', 'website' => 'https://www.mapbox.com', 'domains' => ['mapbox.com']],
            ['slug' => 'openai', 'name' => 'OpenAI', 'category' => 'software', 'website' => 'https://openai.com', 'domains' => ['openai.com']],
            ['slug' => 'anthropic', 'name' => 'Anthropic', 'category' => 'software', 'website' => 'https://www.anthropic.com', 'domains' => ['anthropic.com']],
            ['slug' => 'mistral-ai', 'name' => 'Mistral AI', 'category' => 'software', 'website' => 'https://mistral.ai', 'domains' => ['mistral.ai']],
            ['slug' => 'deepl', 'name' => 'DeepL', 'category' => 'software', 'website' => 'https://www.deepl.com', 'domains' => ['deepl.com']],
            ['slug' => 'grammarly', 'name' => 'Grammarly', 'category' => 'software', 'website' => 'https://www.grammarly.com', 'domains' => ['grammarly.com']],
            ['slug' => '1password', 'name' => '1Password', 'category' => 'software', 'website' => 'https://1password.com', 'domains' => ['1password.com']],
            ['slug' => 'bitwarden', 'name' => 'Bitwarden', 'category' => 'software', 'website' => 'https://bitwarden.com', 'domains' => ['bitwarden.com']],
            ['slug' => 'nordvpn', 'name' => 'NordVPN', 'category' => 'software', 'website' => 'https://nordvpn.com', 'domains' => ['nordvpn.com']],
            ['slug' => 'proton', 'name' => 'Proton', 'category' => 'software', 'website' => 'https://proton.me', 'domains' => ['proton.me', 'protonmail.com']],
            ['slug' => 'holded', 'name' => 'Holded', 'category' => 'software', 'website' => 'https://www.holded.com', 'domains' => ['holded.com']],
            ['slug' => 'quipu', 'name' => 'Quipu', 'category' => 'software', 'website' => 'https://getquipu.com', 'domains' => ['getquipu.com', 'quipu.com']],
            ['slug' => 'sage', 'name' => 'Sage', 'category' => 'software', 'website' => 'https://www.sage.com', 'domains' => ['sage.com']],
            ['slug' => 'wolters-kluwer', 'name' => 'Wolters Kluwer', 'category' => 'software', 'website' => 'https://www.wolterskluwer.com', 'domains' => ['wolterskluwer.com', 'a3software.com']],

            // --- Hosting y dominios ---
            ['slug' => 'ovh', 'name' => 'OVHcloud', 'category' => 'hosting', 'website' => 'https://www.ovhcloud.com', 'domains' => ['ovh.com', 'ovhcloud.com', 'ovh.es']],
            ['slug' => 'aws', 'name' => 'Amazon Web Services', 'category' => 'hosting', 'website' => 'https://aws.amazon.com', 'domains' => ['aws.amazon.com', 'amazonaws.com']],
            ['slug' => 'cloudflare', 'name' => 'Cloudflare', 'category' => 'hosting', 'website' => 'https://www.cloudflare.com', 'domains' => ['cloudflare.com']],
            ['slug' => 'digitalocean', 'name' => 'DigitalOcean', 'category' => 'hosting', 'website' => 'https://www.digitalocean.com', 'domains' => ['digitalocean.com']],
            ['slug' => 'hetzner', 'name' => 'Hetzner', 'category' => 'hosting', 'website' => 'https://www.hetzner.com', 'domains' => ['hetzner.com', 'hetzner.de']],
            ['slug' => 'vercel', 'name' => 'Vercel', 'category' => 'hosting', 'website' => 'https://vercel.com', 'domains' => ['vercel.com']],
            ['slug' => 'netlify', 'name' => 'Netlify', 'category' => 'hosting', 'website' => 'https://www.netlify.com', 'domains' => ['netlify.com']],
            ['slug' => 'ionos', 'name' => 'IONOS', 'category' => 'hosting', 'website' => 'https://www.ionos.es', 'domains' => ['ionos.es', 'ionos.com', '1and1.com']],
            ['slug' => 'raiola-networks', 'name' => 'Raiola Networks', 'category' => 'hosting', 'website' => 'https://raiolanetworks.es', 'domains' => ['raiolanetworks.es']],
            ['slug' => 'webempresa', 'name' => 'Webempresa', 'category' => 'hosting', 'website' => 'https://www.webempresa.com', 'domains' => ['webempresa.com']],
            ['slug' => 'dondominio', 'name' => 'DonDominio', 'category' => 'hosting', 'website' => 'https://www.dondominio.com', 'domains' => ['dondominio.com']],
            ['slug' => 'nominalia', 'name' => 'Nominalia', 'category' => 'hosting', 'website' => 'https://www.nominalia.com', 'domains' => ['nominalia.com']],
            ['slug' => 'arsys', 'name' => 'Arsys', 'category' => 'hosting', 'website' => 'https://www.arsys.es', 'domains' => ['arsys.es']],
            ['slug' => 'cdmon', 'name' => 'CDMON', 'category' => 'hosting', 'website' => 'https://cdmon.com', 'domains' => ['cdmon.com']],
            ['slug' => 'dinahosting', 'name' => 'Dinahosting', 'category' => 'hosting', 'website' => 'https://dinahosting.com', 'domains' => ['dinahosting.com']],
            ['slug' => 'sered', 'name' => 'Sered', 'category' => 'hosting', 'website' => 'https://sered.net', 'domains' => ['sered.net']],
            ['slug' => 'axarnet', 'name' => 'Axarnet', 'category' => 'hosting', 'website' => 'https://axarnet.es', 'domains' => ['axarnet.es']],
            ['slug' => 'lucushost', 'name' => 'LucusHost', 'category' => 'hosting', 'website' => 'https://www.lucushost.com', 'domains' => ['lucushost.com']],
            ['slug' => 'hostinger', 'name' => 'Hostinger', 'category' => 'hosting', 'website' => 'https://www.hostinger.es', 'domains' => ['hostinger.es', 'hostinger.com']],
            ['slug' => 'siteground', 'name' => 'SiteGround', 'category' => 'hosting', 'website' => 'https://www.siteground.es', 'domains' => ['siteground.es', 'siteground.com']],
            ['slug' => 'cloudways', 'name' => 'Cloudways', 'category' => 'hosting', 'website' => 'https://www.cloudways.com', 'domains' => ['cloudways.com']],
            ['slug' => 'godaddy', 'name' => 'GoDaddy', 'category' => 'hosting', 'website' => 'https://www.godaddy.com', 'domains' => ['godaddy.com']],
            ['slug' => 'namecheap', 'name' => 'Namecheap', 'category' => 'hosting', 'website' => 'https://www.namecheap.com', 'domains' => ['namecheap.com']],
            ['slug' => 'squarespace', 'name' => 'Squarespace', 'category' => 'hosting', 'website' => 'https://www.squarespace.com', 'domains' => ['squarespace.com']],
            ['slug' => 'wix', 'name' => 'Wix', 'category' => 'hosting', 'website' => 'https://www.wix.com', 'domains' => ['wix.com']],
            ['slug' => 'wordpress-com', 'name' => 'WordPress.com', 'category' => 'hosting', 'website' => 'https://wordpress.com', 'domains' => ['wordpress.com']],

            // --- Telecomunicaciones ---
            ['slug' => 'movistar', 'name' => 'Movistar', 'category' => 'telecomunicaciones', 'website' => 'https://www.movistar.es', 'domains' => ['movistar.es', 'telefonica.com']],
            ['slug' => 'vodafone', 'name' => 'Vodafone', 'category' => 'telecomunicaciones', 'website' => 'https://www.vodafone.es', 'domains' => ['vodafone.es', 'vodafone.com']],
            ['slug' => 'orange', 'name' => 'Orange', 'category' => 'telecomunicaciones', 'website' => 'https://www.orange.es', 'domains' => ['orange.es', 'orange.com']],
            ['slug' => 'yoigo', 'name' => 'Yoigo', 'category' => 'telecomunicaciones', 'website' => 'https://www.yoigo.com', 'domains' => ['yoigo.com']],
            ['slug' => 'masmovil', 'name' => 'MásMóvil', 'category' => 'telecomunicaciones', 'website' => 'https://www.masmovil.es', 'domains' => ['masmovil.es', 'masmovil.com']],
            ['slug' => 'digi', 'name' => 'DIGI', 'category' => 'telecomunicaciones', 'website' => 'https://www.digimobil.es', 'domains' => ['digimobil.es', 'digi.pt']],
            ['slug' => 'jazztel', 'name' => 'Jazztel', 'category' => 'telecomunicaciones', 'website' => 'https://www.jazztel.com', 'domains' => ['jazztel.com']],
            ['slug' => 'pepephone', 'name' => 'Pepephone', 'category' => 'telecomunicaciones', 'website' => 'https://www.pepephone.com', 'domains' => ['pepephone.com']],
            ['slug' => 'lowi', 'name' => 'Lowi', 'category' => 'telecomunicaciones', 'website' => 'https://www.lowi.es', 'domains' => ['lowi.es']],
            ['slug' => 'o2', 'name' => 'O2', 'category' => 'telecomunicaciones', 'website' => 'https://www.o2online.es', 'domains' => ['o2online.es']],
            ['slug' => 'simyo', 'name' => 'Simyo', 'category' => 'telecomunicaciones', 'website' => 'https://www.simyo.es', 'domains' => ['simyo.es']],
            ['slug' => 'finetwork', 'name' => 'Finetwork', 'category' => 'telecomunicaciones', 'website' => 'https://www.finetwork.com', 'domains' => ['finetwork.com']],
            ['slug' => 'adamo', 'name' => 'Adamo', 'category' => 'telecomunicaciones', 'website' => 'https://www.adamo.es', 'domains' => ['adamo.es']],
            ['slug' => 'euskaltel', 'name' => 'Euskaltel', 'category' => 'telecomunicaciones', 'website' => 'https://www.euskaltel.com', 'domains' => ['euskaltel.com']],
            ['slug' => 'avatel', 'name' => 'Avatel', 'category' => 'telecomunicaciones', 'website' => 'https://www.avatel.es', 'domains' => ['avatel.es']],
            ['slug' => 'twilio', 'name' => 'Twilio', 'category' => 'telecomunicaciones', 'website' => 'https://www.twilio.com', 'domains' => ['twilio.com']],
            ['slug' => 'vonage', 'name' => 'Vonage', 'category' => 'telecomunicaciones', 'website' => 'https://www.vonage.com', 'domains' => ['vonage.com']],
            ['slug' => 'ringcentral', 'name' => 'RingCentral', 'category' => 'telecomunicaciones', 'website' => 'https://www.ringcentral.com', 'domains' => ['ringcentral.com']],
            ['slug' => 'aircall', 'name' => 'Aircall', 'category' => 'telecomunicaciones', 'website' => 'https://aircall.io', 'domains' => ['aircall.io']],

            // --- Seguros ---
            ['slug' => 'mapfre', 'name' => 'Mapfre', 'category' => 'seguros', 'website' => 'https://www.mapfre.es', 'domains' => ['mapfre.es', 'mapfre.com']],
            ['slug' => 'mutua-madrilena', 'name' => 'Mutua Madrileña', 'category' => 'seguros', 'website' => 'https://www.mutua.es', 'domains' => ['mutua.es', 'mutuamadrilena.es']],
            ['slug' => 'linea-directa', 'name' => 'Línea Directa', 'category' => 'seguros', 'website' => 'https://www.lineadirecta.com', 'domains' => ['lineadirecta.com', 'lineadirecta.es']],
            ['slug' => 'adeslas', 'name' => 'Adeslas', 'category' => 'seguros', 'website' => 'https://www.adeslas.es', 'domains' => ['adeslas.es']],
            ['slug' => 'sanitas', 'name' => 'Sanitas', 'category' => 'seguros', 'website' => 'https://www.sanitas.es', 'domains' => ['sanitas.es']],
            ['slug' => 'dkv', 'name' => 'DKV', 'category' => 'seguros', 'website' => 'https://www.dkv.es', 'domains' => ['dkv.es', 'dkv.com']],
            ['slug' => 'asisa', 'name' => 'Asisa', 'category' => 'seguros', 'website' => 'https://www.asisa.es', 'domains' => ['asisa.es']],
            ['slug' => 'caser', 'name' => 'Caser', 'category' => 'seguros', 'website' => 'https://www.caser.es', 'domains' => ['caser.es']],
            ['slug' => 'reale', 'name' => 'Reale', 'category' => 'seguros', 'website' => 'https://www.reale.es', 'domains' => ['reale.es']],
            ['slug' => 'generali', 'name' => 'Generali', 'category' => 'seguros', 'website' => 'https://www.generali.es', 'domains' => ['generali.es', 'generali.com']],
            ['slug' => 'axa', 'name' => 'AXA', 'category' => 'seguros', 'website' => 'https://www.axa.es', 'domains' => ['axa.es', 'axa.com']],
            ['slug' => 'allianz', 'name' => 'Allianz', 'category' => 'seguros', 'website' => 'https://www.allianz.es', 'domains' => ['allianz.es', 'allianz.com']],
            ['slug' => 'zurich', 'name' => 'Zurich', 'category' => 'seguros', 'website' => 'https://www.zurich.es', 'domains' => ['zurich.es', 'zurich.com']],
            ['slug' => 'pelayo', 'name' => 'Pelayo', 'category' => 'seguros', 'website' => 'https://www.pelayo.com', 'domains' => ['pelayo.com']],
            ['slug' => 'catalana-occidente', 'name' => 'Catalana Occidente', 'category' => 'seguros', 'website' => 'https://www.gco.com', 'domains' => ['gco.com', 'catalanaoccidente.com']],
            ['slug' => 'occident', 'name' => 'Occident', 'category' => 'seguros', 'website' => 'https://www.occident.com', 'domains' => ['occident.com']],
            ['slug' => 'santalucia', 'name' => 'Santalucía', 'category' => 'seguros', 'website' => 'https://www.santalucia.es', 'domains' => ['santalucia.es']],
            ['slug' => 'aegon', 'name' => 'Aegon', 'category' => 'seguros', 'website' => 'https://www.aegon.es', 'domains' => ['aegon.es']],
            ['slug' => 'race', 'name' => 'RACE', 'category' => 'seguros', 'website' => 'https://www.race.es', 'domains' => ['race.es']],
            ['slug' => 'racc', 'name' => 'RACC', 'category' => 'seguros', 'website' => 'https://www.racc.es', 'domains' => ['racc.es']],

            // --- Suministros ---
            ['slug' => 'iberdrola', 'name' => 'Iberdrola', 'category' => 'suministros', 'website' => 'https://www.iberdrola.es', 'domains' => ['iberdrola.es', 'iberdrola.com']],
            ['slug' => 'endesa', 'name' => 'Endesa', 'category' => 'suministros', 'website' => 'https://www.endesa.com', 'domains' => ['endesa.com', 'endesa.es']],
            ['slug' => 'naturgy', 'name' => 'Naturgy', 'category' => 'suministros', 'website' => 'https://www.naturgy.es', 'domains' => ['naturgy.es', 'naturgy.com']],
            ['slug' => 'repsol', 'name' => 'Repsol', 'category' => 'suministros', 'website' => 'https://www.repsol.com', 'domains' => ['repsol.com', 'repsol.es']],
            ['slug' => 'holaluz', 'name' => 'Holaluz', 'category' => 'suministros', 'website' => 'https://www.holaluz.com', 'domains' => ['holaluz.com']],
            ['slug' => 'cepsa', 'name' => 'Cepsa', 'category' => 'suministros', 'website' => 'https://www.cepsa.com', 'domains' => ['cepsa.com', 'cepsa.es']],
            ['slug' => 'galp', 'name' => 'Galp', 'category' => 'suministros', 'website' => 'https://www.galp.com', 'domains' => ['galp.com']],
            ['slug' => 'aqualia', 'name' => 'Aqualia', 'category' => 'suministros', 'website' => 'https://www.aqualia.es', 'domains' => ['aqualia.es', 'aqualia.com']],
            ['slug' => 'canal-isabel-ii', 'name' => 'Canal de Isabel II', 'category' => 'suministros', 'website' => 'https://www.canal-isabel-ii.es', 'domains' => ['canal-isabel-ii.es']],

            // --- Marketing y publicidad ---
            ['slug' => 'mailchimp', 'name' => 'Mailchimp', 'category' => 'marketing', 'website' => 'https://mailchimp.com', 'domains' => ['mailchimp.com']],
            ['slug' => 'mailerlite', 'name' => 'MailerLite', 'category' => 'marketing', 'website' => 'https://www.mailerlite.com', 'domains' => ['mailerlite.com']],
            ['slug' => 'brevo', 'name' => 'Brevo', 'category' => 'marketing', 'website' => 'https://www.brevo.com', 'domains' => ['brevo.com', 'sendinblue.com']],
            ['slug' => 'acumbamail', 'name' => 'Acumbamail', 'category' => 'marketing', 'website' => 'https://acumbamail.com', 'domains' => ['acumbamail.com']],
            ['slug' => 'mailrelay', 'name' => 'Mailrelay', 'category' => 'marketing', 'website' => 'https://mailrelay.com', 'domains' => ['mailrelay.com']],
            ['slug' => 'sendgrid', 'name' => 'SendGrid', 'category' => 'marketing', 'website' => 'https://sendgrid.com', 'domains' => ['sendgrid.com']],
            ['slug' => 'postmark', 'name' => 'Postmark', 'category' => 'marketing', 'website' => 'https://postmarkapp.com', 'domains' => ['postmarkapp.com']],
            ['slug' => 'resend', 'name' => 'Resend', 'category' => 'marketing', 'website' => 'https://resend.com', 'domains' => ['resend.com']],
            ['slug' => 'hubspot', 'name' => 'HubSpot', 'category' => 'marketing', 'website' => 'https://www.hubspot.com', 'domains' => ['hubspot.com']],
            ['slug' => 'semrush', 'name' => 'Semrush', 'category' => 'marketing', 'website' => 'https://www.semrush.com', 'domains' => ['semrush.com']],
            ['slug' => 'ahrefs', 'name' => 'Ahrefs', 'category' => 'marketing', 'website' => 'https://ahrefs.com', 'domains' => ['ahrefs.com']],
            ['slug' => 'moz', 'name' => 'Moz', 'category' => 'marketing', 'website' => 'https://moz.com', 'domains' => ['moz.com']],
            ['slug' => 'hotjar', 'name' => 'Hotjar', 'category' => 'marketing', 'website' => 'https://www.hotjar.com', 'domains' => ['hotjar.com']],
            ['slug' => 'plausible', 'name' => 'Plausible', 'category' => 'marketing', 'website' => 'https://plausible.io', 'domains' => ['plausible.io']],
            ['slug' => 'fathom', 'name' => 'Fathom Analytics', 'category' => 'marketing', 'website' => 'https://usefathom.com', 'domains' => ['usefathom.com']],
            ['slug' => 'matomo', 'name' => 'Matomo', 'category' => 'marketing', 'website' => 'https://matomo.org', 'domains' => ['matomo.org', 'innocraft.com']],
            ['slug' => 'meta', 'name' => 'Meta', 'category' => 'marketing', 'website' => 'https://www.facebook.com/business', 'domains' => ['meta.com', 'facebook.com']],
            ['slug' => 'linkedin', 'name' => 'LinkedIn', 'category' => 'marketing', 'website' => 'https://business.linkedin.com', 'domains' => ['linkedin.com']],

            // --- Formación ---
            ['slug' => 'udemy', 'name' => 'Udemy', 'category' => 'formacion', 'website' => 'https://www.udemy.com', 'domains' => ['udemy.com']],
            ['slug' => 'coursera', 'name' => 'Coursera', 'category' => 'formacion', 'website' => 'https://www.coursera.org', 'domains' => ['coursera.org']],
            ['slug' => 'platzi', 'name' => 'Platzi', 'category' => 'formacion', 'website' => 'https://platzi.com', 'domains' => ['platzi.com']],
            ['slug' => 'domestika', 'name' => 'Domestika', 'category' => 'formacion', 'website' => 'https://www.domestika.org', 'domains' => ['domestika.org']],
            ['slug' => 'skool', 'name' => 'Skool', 'category' => 'formacion', 'website' => 'https://www.skool.com', 'domains' => ['skool.com']],

            // --- Transporte y logística ---
            ['slug' => 'correos', 'name' => 'Correos', 'category' => 'transporte', 'website' => 'https://www.correos.es', 'domains' => ['correos.es']],
            ['slug' => 'seur', 'name' => 'SEUR', 'category' => 'transporte', 'website' => 'https://www.seur.com', 'domains' => ['seur.com']],
            ['slug' => 'mrw', 'name' => 'MRW', 'category' => 'transporte', 'website' => 'https://www.mrw.es', 'domains' => ['mrw.es']],
            ['slug' => 'nacex', 'name' => 'Nacex', 'category' => 'transporte', 'website' => 'https://www.nacex.es', 'domains' => ['nacex.es']],
            ['slug' => 'envialia', 'name' => 'Envialia', 'category' => 'transporte', 'website' => 'https://www.envialia.com', 'domains' => ['envialia.com']],
            ['slug' => 'dhl', 'name' => 'DHL', 'category' => 'transporte', 'website' => 'https://www.dhl.com', 'domains' => ['dhl.com']],
            ['slug' => 'ups', 'name' => 'UPS', 'category' => 'transporte', 'website' => 'https://www.ups.com', 'domains' => ['ups.com']],
            ['slug' => 'gls', 'name' => 'GLS', 'category' => 'transporte', 'website' => 'https://gls-group.eu', 'domains' => ['gls-group.eu', 'gls-spain.es']],
            ['slug' => 'renfe', 'name' => 'Renfe', 'category' => 'transporte', 'website' => 'https://www.renfe.com', 'domains' => ['renfe.com']],
            ['slug' => 'cabify', 'name' => 'Cabify', 'category' => 'transporte', 'website' => 'https://cabify.com', 'domains' => ['cabify.com']],
            ['slug' => 'uber', 'name' => 'Uber', 'category' => 'transporte', 'website' => 'https://www.uber.com', 'domains' => ['uber.com']],
            ['slug' => 'bolt', 'name' => 'Bolt', 'category' => 'transporte', 'website' => 'https://bolt.eu', 'domains' => ['bolt.eu']],

            // --- Servicios profesionales ---
            ['slug' => 'amazon', 'name' => 'Amazon', 'category' => 'otros', 'website' => 'https://www.amazon.es', 'domains' => ['amazon.es', 'amazon.com']],
            ['slug' => 'netflix', 'name' => 'Netflix', 'category' => 'otros', 'website' => 'https://www.netflix.com', 'domains' => ['netflix.com']],
            ['slug' => 'spotify', 'name' => 'Spotify', 'category' => 'otros', 'website' => 'https://www.spotify.com', 'domains' => ['spotify.com']],
        ];
    }
}
