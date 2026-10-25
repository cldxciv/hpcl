<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:s="http://www.sitemaps.org/schemas/sitemap/0.9"
    xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">
    <xsl:output method="html" encoding="UTF-8" indent="yes"/>

    <xsl:template match="/">
        <html lang="en">
        <head>
            <meta charset="UTF-8"/>
            <meta name="viewport" content="width=device-width, initial-scale=1"/>
            <title>Sitemap</title>
            <style>
                :root {
                    color-scheme: light;
                    --bg: #f5f7fb;
                    --card: #ffffff;
                    --text: #1f2937;
                    --muted: #6b7280;
                    --line: #dbe2ef;
                    --accent: #0f766e;
                    --accent-soft: #e6fffb;
                }
                * { box-sizing: border-box; }
                body {
                    margin: 0;
                    font: 15px/1.55 "Segoe UI", Tahoma, sans-serif;
                    color: var(--text);
                    background: radial-gradient(circle at top right, #e3f2ff, var(--bg) 48%);
                }
                .wrap {
                    max-width: 1100px;
                    margin: 0 auto;
                    padding: 24px 16px 40px;
                }
                .head {
                    background: linear-gradient(120deg, #0f766e, #0e7490);
                    color: #fff;
                    border-radius: 14px;
                    padding: 16px 18px;
                    margin-bottom: 14px;
                }
                h1 { margin: 0 0 6px; font-size: 22px; }
                p { margin: 0; color: rgba(255,255,255,0.9); }
                .card {
                    background: var(--card);
                    border: 1px solid var(--line);
                    border-radius: 12px;
                    overflow: hidden;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                }
                th, td {
                    padding: 11px 12px;
                    border-bottom: 1px solid var(--line);
                    text-align: left;
                    vertical-align: top;
                }
                th {
                    font-size: 12px;
                    letter-spacing: .04em;
                    text-transform: uppercase;
                    color: var(--muted);
                    background: #f8fafc;
                }
                tr:last-child td { border-bottom: none; }
                a {
                    color: #0f4c81;
                    text-decoration: none;
                    word-break: break-all;
                }
                a:hover { text-decoration: underline; }
                .tag {
                    display: inline-block;
                    padding: 2px 8px;
                    border-radius: 999px;
                    background: var(--accent-soft);
                    color: var(--accent);
                    font-size: 12px;
                }
                .muted { color: var(--muted); }
                @media (max-width: 760px) {
                    th:nth-child(3), td:nth-child(3) { display: none; }
                }
            </style>
        </head>
        <body>
            <div class="wrap">
                <div class="head">
                    <h1>Sitemap</h1>
                    <p>
                        <xsl:choose>
                            <xsl:when test="s:sitemapindex">Sitemap index with child sitemap files.</xsl:when>
                            <xsl:otherwise>URL sitemap with indexed pages.</xsl:otherwise>
                        </xsl:choose>
                    </p>
                </div>

                <xsl:choose>
                    <xsl:when test="s:sitemapindex">
                        <div class="card">
                            <table>
                                <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Location</th>
                                    <th>Last Modified</th>
                                </tr>
                                </thead>
                                <tbody>
                                <xsl:for-each select="s:sitemapindex/s:sitemap">
                                    <tr>
                                        <td><span class="tag">Sitemap</span></td>
                                        <td>
                                            <a href="{s:loc}">
                                                <xsl:value-of select="s:loc"/>
                                            </a>
                                        </td>
                                        <td class="muted">
                                            <xsl:choose>
                                                <xsl:when test="string(s:lastmod) != ''"><xsl:value-of select="s:lastmod"/></xsl:when>
                                                <xsl:otherwise>-</xsl:otherwise>
                                            </xsl:choose>
                                        </td>
                                    </tr>
                                </xsl:for-each>
                                </tbody>
                            </table>
                        </div>
                    </xsl:when>

                    <xsl:otherwise>
                        <div class="card">
                            <table>
                                <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Location</th>
                                    <th>Meta</th>
                                </tr>
                                </thead>
                                <tbody>
                                <xsl:for-each select="s:urlset/s:url">
                                    <tr>
                                        <td>
                                            <xsl:choose>
                                                <xsl:when test="count(video:video) &gt; 0"><span class="tag">Video URL</span></xsl:when>
                                                <xsl:otherwise><span class="tag">URL</span></xsl:otherwise>
                                            </xsl:choose>
                                        </td>
                                        <td>
                                            <a href="{s:loc}">
                                                <xsl:value-of select="s:loc"/>
                                            </a>
                                        </td>
                                        <td class="muted">
                                            <xsl:choose>
                                                <xsl:when test="count(video:video) &gt; 0">
                                                    videos:
                                                    <xsl:value-of select="count(video:video)"/>
                                                </xsl:when>
                                                <xsl:when test="string(s:lastmod) != ''">
                                                    <xsl:value-of select="s:lastmod"/>
                                                </xsl:when>
                                                <xsl:otherwise>-</xsl:otherwise>
                                            </xsl:choose>
                                        </td>
                                    </tr>
                                </xsl:for-each>
                                </tbody>
                            </table>
                        </div>
                    </xsl:otherwise>
                </xsl:choose>
            </div>
        </body>
        </html>
    </xsl:template>
</xsl:stylesheet>
