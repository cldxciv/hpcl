# Member creative: how to change the design

Every paid member gets a 1080 x 1080 picture for social media. The site draws it for each member
(`includes/member-creative.php`) on top of a background image, so the design can be changed without
touching the member data.

## Change the look (for example, design it in Canva)

1. Open `sample.png` here. It shows where the member's photo, name, tier badge and profile link land.
2. In Canva (or any design tool) create a **1080 x 1080 px** design. Keep these areas free of busy
   artwork, because text and the round photo are drawn there:
   - top-left: the Pro HPCL logo (x 56, y 44, 250 px wide)
   - top-right: the "MEMBERSHIP PROGRAMME" label
   - the headline "PROUD MEMBER / OF THE PRO HPCL FAMILY" (about y 140 to 350)
   - the round photo (330 px across, centred, y about 390 to 770)
   - the business name and tier badge (about y 790 to 960)
   - the footer band with the profile link (y 1004 to 1080)
3. Export it as **PNG** and replace `bg-square.png` in this folder.
4. Run `php bin/build-creative-assets.php --sample` to refresh `sample.png` and check the result.
5. Upload `bg-square.png` to the server. Existing members' pictures redraw by themselves the next
   time they are opened, because the picture notices that the background changed.

To go back to the built-in design: `php bin/build-creative-assets.php --reset`.

## Change positions, sizes, colours or wording

Everything is in one array, `memberCreativeLayout()` at the top of `includes/member-creative.php`
(text, font sizes, positions, colours). After a layout change, raise `CREATIVE_VERSION` in that file
so every saved creative is drawn again.

Fonts are in `assets/fonts` (Bebas Neue and Barlow, both under the SIL Open Font License).
