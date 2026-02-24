/**
 * Example SVG templates for venue seat maps.
 *
 * These are embedded as string constants so users can download them
 * directly from the venue editor without needing a separate file endpoint.
 */

/**
 * Flat IDs example — element IDs follow the pattern: section-row-seat
 *
 * Layout: A small theater with two sections (Orchestra + Balcony),
 * each with multiple rows of circle seats.
 *
 * Naming convention:  orchestra-rowA-seat1, balcony-rowB-seat3, etc.
 * Category via class:  class="vip" on orchestra seats, class="standard" on balcony.
 */
export const EXAMPLE_SVG_FLAT_IDS = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 620 520">
  <!--
    EXAMPLE VENUE — Flat Element IDs Mode
    ======================================
    Each seat element has an id in the format:  section-row-seat
    The parser splits on the dash delimiter to extract section, row, and seat.

    Optionally add class="categoryName" or data-category="categoryName"
    to assign pricing categories to seats or sections.
  -->

  <!-- ── Stage ────────────────────────────────────────────────────── -->
  <rect x="110" y="20" width="400" height="60" rx="30" fill="#e2e8f0" stroke="#94a3b8" stroke-width="1.5"/>
  <text x="310" y="57" text-anchor="middle" fill="#64748b" font-size="18" font-family="Arial, Helvetica, sans-serif" font-weight="600">STAGE</text>

  <!-- ── Orchestra Section (VIP) ── 3 rows x 7 seats ─────────────── -->
  <text x="310" y="118" text-anchor="middle" fill="#475569" font-size="12" font-family="Arial, Helvetica, sans-serif" font-weight="500">ORCHESTRA</text>

  <!-- Orchestra Row A -->
  <text x="100" y="148" text-anchor="middle" fill="#94a3b8" font-size="11" font-family="Arial, Helvetica, sans-serif">A</text>
  <circle id="orchestra-rowA-seat1" cx="160" cy="143" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1" class="vip"/>
  <circle id="orchestra-rowA-seat2" cx="210" cy="143" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1" class="vip"/>
  <circle id="orchestra-rowA-seat3" cx="260" cy="143" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1" class="vip"/>
  <circle id="orchestra-rowA-seat4" cx="310" cy="143" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1" class="vip"/>
  <circle id="orchestra-rowA-seat5" cx="360" cy="143" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1" class="vip"/>
  <circle id="orchestra-rowA-seat6" cx="410" cy="143" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1" class="vip"/>
  <circle id="orchestra-rowA-seat7" cx="460" cy="143" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1" class="vip"/>

  <!-- Orchestra Row B -->
  <text x="100" y="193" text-anchor="middle" fill="#94a3b8" font-size="11" font-family="Arial, Helvetica, sans-serif">B</text>
  <circle id="orchestra-rowB-seat1" cx="160" cy="188" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1" class="vip"/>
  <circle id="orchestra-rowB-seat2" cx="210" cy="188" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1" class="vip"/>
  <circle id="orchestra-rowB-seat3" cx="260" cy="188" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1" class="vip"/>
  <circle id="orchestra-rowB-seat4" cx="310" cy="188" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1" class="vip"/>
  <circle id="orchestra-rowB-seat5" cx="360" cy="188" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1" class="vip"/>
  <circle id="orchestra-rowB-seat6" cx="410" cy="188" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1" class="vip"/>
  <circle id="orchestra-rowB-seat7" cx="460" cy="188" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1" class="vip"/>

  <!-- Orchestra Row C -->
  <text x="100" y="238" text-anchor="middle" fill="#94a3b8" font-size="11" font-family="Arial, Helvetica, sans-serif">C</text>
  <circle id="orchestra-rowC-seat1" cx="160" cy="233" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1" class="vip"/>
  <circle id="orchestra-rowC-seat2" cx="210" cy="233" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1" class="vip"/>
  <circle id="orchestra-rowC-seat3" cx="260" cy="233" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1" class="vip"/>
  <circle id="orchestra-rowC-seat4" cx="310" cy="233" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1" class="vip"/>
  <circle id="orchestra-rowC-seat5" cx="360" cy="233" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1" class="vip"/>
  <circle id="orchestra-rowC-seat6" cx="410" cy="233" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1" class="vip"/>
  <circle id="orchestra-rowC-seat7" cx="460" cy="233" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1" class="vip"/>

  <!-- ── Aisle divider ────────────────────────────────────────────── -->
  <line x1="130" y1="275" x2="490" y2="275" stroke="#e2e8f0" stroke-width="1" stroke-dasharray="4 4"/>

  <!-- ── Balcony Section (Standard) ── 3 rows x 9 seats ──────────── -->
  <text x="310" y="303" text-anchor="middle" fill="#475569" font-size="12" font-family="Arial, Helvetica, sans-serif" font-weight="500">BALCONY</text>

  <!-- Balcony Row A -->
  <text x="85" y="333" text-anchor="middle" fill="#94a3b8" font-size="11" font-family="Arial, Helvetica, sans-serif">A</text>
  <circle id="balcony-rowA-seat1" cx="130" cy="328" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1" class="standard"/>
  <circle id="balcony-rowA-seat2" cx="175" cy="328" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1" class="standard"/>
  <circle id="balcony-rowA-seat3" cx="220" cy="328" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1" class="standard"/>
  <circle id="balcony-rowA-seat4" cx="265" cy="328" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1" class="standard"/>
  <circle id="balcony-rowA-seat5" cx="310" cy="328" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1" class="standard"/>
  <circle id="balcony-rowA-seat6" cx="355" cy="328" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1" class="standard"/>
  <circle id="balcony-rowA-seat7" cx="400" cy="328" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1" class="standard"/>
  <circle id="balcony-rowA-seat8" cx="445" cy="328" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1" class="standard"/>
  <circle id="balcony-rowA-seat9" cx="490" cy="328" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1" class="standard"/>

  <!-- Balcony Row B -->
  <text x="85" y="378" text-anchor="middle" fill="#94a3b8" font-size="11" font-family="Arial, Helvetica, sans-serif">B</text>
  <circle id="balcony-rowB-seat1" cx="130" cy="373" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1" class="standard"/>
  <circle id="balcony-rowB-seat2" cx="175" cy="373" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1" class="standard"/>
  <circle id="balcony-rowB-seat3" cx="220" cy="373" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1" class="standard"/>
  <circle id="balcony-rowB-seat4" cx="265" cy="373" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1" class="standard"/>
  <circle id="balcony-rowB-seat5" cx="310" cy="373" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1" class="standard"/>
  <circle id="balcony-rowB-seat6" cx="355" cy="373" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1" class="standard"/>
  <circle id="balcony-rowB-seat7" cx="400" cy="373" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1" class="standard"/>
  <circle id="balcony-rowB-seat8" cx="445" cy="373" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1" class="standard"/>
  <circle id="balcony-rowB-seat9" cx="490" cy="373" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1" class="standard"/>

  <!-- Balcony Row C -->
  <text x="85" y="423" text-anchor="middle" fill="#94a3b8" font-size="11" font-family="Arial, Helvetica, sans-serif">C</text>
  <circle id="balcony-rowC-seat1" cx="130" cy="418" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1" class="standard"/>
  <circle id="balcony-rowC-seat2" cx="175" cy="418" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1" class="standard"/>
  <circle id="balcony-rowC-seat3" cx="220" cy="418" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1" class="standard"/>
  <circle id="balcony-rowC-seat4" cx="265" cy="418" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1" class="standard"/>
  <circle id="balcony-rowC-seat5" cx="310" cy="418" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1" class="standard"/>
  <circle id="balcony-rowC-seat6" cx="355" cy="418" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1" class="standard"/>
  <circle id="balcony-rowC-seat7" cx="400" cy="418" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1" class="standard"/>
  <circle id="balcony-rowC-seat8" cx="445" cy="418" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1" class="standard"/>
  <circle id="balcony-rowC-seat9" cx="490" cy="418" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1" class="standard"/>

  <!-- ── Legend ────────────────────────────────────────────────────── -->
  <rect x="130" y="465" width="14" height="14" rx="7" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1"/>
  <text x="150" y="476" fill="#64748b" font-size="11" font-family="Arial, Helvetica, sans-serif">VIP (class="vip")</text>
  <rect x="310" y="465" width="14" height="14" rx="7" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
  <text x="330" y="476" fill="#64748b" font-size="11" font-family="Arial, Helvetica, sans-serif">Standard (class="standard")</text>

  <!-- ── ID format annotation ─────────────────────────────────────── -->
  <text x="310" y="505" text-anchor="middle" fill="#94a3b8" font-size="10" font-family="Arial, Helvetica, sans-serif" font-style="italic">ID format: section-row-seat (e.g. orchestra-rowA-seat1)</text>
</svg>`;

/**
 * Nested Groups example — structure is defined by <g> hierarchy instead of IDs.
 *
 * Layout: Same theater concept but using nested SVG groups:
 *   <g id="sectionName"> → <g id="rowName"> → <circle id="seatName"/>
 *
 * This is the pattern produced by Illustrator/Inkscape when using named layers.
 */
export const EXAMPLE_SVG_NESTED_GROUPS = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 620 520">
  <!--
    EXAMPLE VENUE — Nested Group IDs Mode
    ======================================
    Seats are organized by nesting SVG <g> groups:
      <g id="sectionName">         (section layer)
        <g id="rowName">           (row layer)
          <circle id="seatName"/>  (individual seat)
        </g>
      </g>

    This structure matches how Illustrator and Inkscape export named layers.
    Categories can be set via class or data-category on any element.
  -->

  <!-- ── Stage ────────────────────────────────────────────────────── -->
  <rect x="110" y="20" width="400" height="60" rx="30" fill="#e2e8f0" stroke="#94a3b8" stroke-width="1.5"/>
  <text x="310" y="57" text-anchor="middle" fill="#64748b" font-size="18" font-family="Arial, Helvetica, sans-serif" font-weight="600">STAGE</text>

  <!-- ── Orchestra Section ── nested groups with class for category ── -->
  <text x="310" y="118" text-anchor="middle" fill="#475569" font-size="12" font-family="Arial, Helvetica, sans-serif" font-weight="500">ORCHESTRA</text>

  <g id="orchestra" class="vip">
    <g id="row-A">
      <text x="100" y="148" text-anchor="middle" fill="#94a3b8" font-size="11" font-family="Arial, Helvetica, sans-serif">A</text>
      <circle id="seat-1" cx="160" cy="143" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1"/>
      <circle id="seat-2" cx="210" cy="143" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1"/>
      <circle id="seat-3" cx="260" cy="143" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1"/>
      <circle id="seat-4" cx="310" cy="143" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1"/>
      <circle id="seat-5" cx="360" cy="143" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1"/>
      <circle id="seat-6" cx="410" cy="143" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1"/>
      <circle id="seat-7" cx="460" cy="143" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1"/>
    </g>
    <g id="row-B">
      <text x="100" y="193" text-anchor="middle" fill="#94a3b8" font-size="11" font-family="Arial, Helvetica, sans-serif">B</text>
      <circle id="seat-1" cx="160" cy="188" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1"/>
      <circle id="seat-2" cx="210" cy="188" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1"/>
      <circle id="seat-3" cx="260" cy="188" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1"/>
      <circle id="seat-4" cx="310" cy="188" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1"/>
      <circle id="seat-5" cx="360" cy="188" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1"/>
      <circle id="seat-6" cx="410" cy="188" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1"/>
      <circle id="seat-7" cx="460" cy="188" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1"/>
    </g>
    <g id="row-C">
      <text x="100" y="238" text-anchor="middle" fill="#94a3b8" font-size="11" font-family="Arial, Helvetica, sans-serif">C</text>
      <circle id="seat-1" cx="160" cy="233" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1"/>
      <circle id="seat-2" cx="210" cy="233" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1"/>
      <circle id="seat-3" cx="260" cy="233" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1"/>
      <circle id="seat-4" cx="310" cy="233" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1"/>
      <circle id="seat-5" cx="360" cy="233" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1"/>
      <circle id="seat-6" cx="410" cy="233" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1"/>
      <circle id="seat-7" cx="460" cy="233" r="14" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1"/>
    </g>
  </g>

  <!-- ── Aisle divider ────────────────────────────────────────────── -->
  <line x1="130" y1="275" x2="490" y2="275" stroke="#e2e8f0" stroke-width="1" stroke-dasharray="4 4"/>

  <!-- ── Balcony Section ── nested groups ──────────────────────────── -->
  <text x="310" y="303" text-anchor="middle" fill="#475569" font-size="12" font-family="Arial, Helvetica, sans-serif" font-weight="500">BALCONY</text>

  <g id="balcony" class="standard">
    <g id="row-A">
      <text x="85" y="333" text-anchor="middle" fill="#94a3b8" font-size="11" font-family="Arial, Helvetica, sans-serif">A</text>
      <circle id="seat-1" cx="130" cy="328" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
      <circle id="seat-2" cx="175" cy="328" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
      <circle id="seat-3" cx="220" cy="328" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
      <circle id="seat-4" cx="265" cy="328" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
      <circle id="seat-5" cx="310" cy="328" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
      <circle id="seat-6" cx="355" cy="328" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
      <circle id="seat-7" cx="400" cy="328" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
      <circle id="seat-8" cx="445" cy="328" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
      <circle id="seat-9" cx="490" cy="328" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
    </g>
    <g id="row-B">
      <text x="85" y="378" text-anchor="middle" fill="#94a3b8" font-size="11" font-family="Arial, Helvetica, sans-serif">B</text>
      <circle id="seat-1" cx="130" cy="373" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
      <circle id="seat-2" cx="175" cy="373" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
      <circle id="seat-3" cx="220" cy="373" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
      <circle id="seat-4" cx="265" cy="373" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
      <circle id="seat-5" cx="310" cy="373" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
      <circle id="seat-6" cx="355" cy="373" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
      <circle id="seat-7" cx="400" cy="373" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
      <circle id="seat-8" cx="445" cy="373" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
      <circle id="seat-9" cx="490" cy="373" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
    </g>
    <g id="row-C">
      <text x="85" y="423" text-anchor="middle" fill="#94a3b8" font-size="11" font-family="Arial, Helvetica, sans-serif">C</text>
      <circle id="seat-1" cx="130" cy="418" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
      <circle id="seat-2" cx="175" cy="418" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
      <circle id="seat-3" cx="220" cy="418" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
      <circle id="seat-4" cx="265" cy="418" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
      <circle id="seat-5" cx="310" cy="418" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
      <circle id="seat-6" cx="355" cy="418" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
      <circle id="seat-7" cx="400" cy="418" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
      <circle id="seat-8" cx="445" cy="418" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
      <circle id="seat-9" cx="490" cy="418" r="14" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
    </g>
  </g>

  <!-- ── Legend ────────────────────────────────────────────────────── -->
  <rect x="130" y="465" width="14" height="14" rx="7" fill="#e0e7ff" stroke="#a5b4fc" stroke-width="1"/>
  <text x="150" y="476" fill="#64748b" font-size="11" font-family="Arial, Helvetica, sans-serif">VIP (class="vip" on group)</text>
  <rect x="310" y="465" width="14" height="14" rx="7" fill="#f0fdf4" stroke="#86efac" stroke-width="1"/>
  <text x="330" y="476" fill="#64748b" font-size="11" font-family="Arial, Helvetica, sans-serif">Standard (class="standard" on group)</text>

  <!-- ── Structure annotation ─────────────────────────────────────── -->
  <text x="310" y="505" text-anchor="middle" fill="#94a3b8" font-size="10" font-family="Arial, Helvetica, sans-serif" font-style="italic">Structure: &lt;g id="section"&gt; &gt; &lt;g id="row"&gt; &gt; &lt;circle id="seat"/&gt;</text>
</svg>`;

/**
 * Overview SVG for the Flat IDs example.
 *
 * A simplified venue map showing clickable section areas (no individual seats).
 * Section element IDs must match the section IDs parsed from the seat SVG
 * (orchestra, balcony).
 */
export const EXAMPLE_OVERVIEW_SVG_FLAT_IDS = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 620 420">
  <!-- Stage -->
  <rect x="110" y="20" width="400" height="60" rx="30" fill="#e2e8f0" stroke="#94a3b8" stroke-width="1.5"/>
  <text x="310" y="57" text-anchor="middle" fill="#64748b" font-size="18" font-family="Arial, Helvetica, sans-serif" font-weight="600">STAGE</text>

  <!-- Orchestra section area (clickable) — id must match seat SVG section -->
  <rect id="orchestra" x="130" y="110" width="360" height="110" rx="12"
        fill="#ede9fe" stroke="#a5b4fc" stroke-width="2" data-category="vip"/>
  <text x="310" y="160" text-anchor="middle" fill="#5b21b6" font-size="20"
        font-family="Arial, Helvetica, sans-serif" font-weight="600" pointer-events="none">ORCHESTRA</text>
  <text x="310" y="185" text-anchor="middle" fill="#7c3aed" font-size="13"
        font-family="Arial, Helvetica, sans-serif" pointer-events="none">VIP \u2014 21 seats</text>

  <!-- Aisle -->
  <line x1="130" y1="240" x2="490" y2="240" stroke="#cbd5e1" stroke-width="1" stroke-dasharray="6 4"/>

  <!-- Balcony section area (clickable) — id must match seat SVG section -->
  <rect id="balcony" x="100" y="260" width="420" height="110" rx="12"
        fill="#d1fae5" stroke="#86efac" stroke-width="2" data-category="standard"/>
  <text x="310" y="310" text-anchor="middle" fill="#065f46" font-size="20"
        font-family="Arial, Helvetica, sans-serif" font-weight="600" pointer-events="none">BALCONY</text>
  <text x="310" y="335" text-anchor="middle" fill="#047857" font-size="13"
        font-family="Arial, Helvetica, sans-serif" pointer-events="none">Standard \u2014 27 seats</text>

  <!-- Instruction -->
  <text x="310" y="400" text-anchor="middle" fill="#94a3b8" font-size="11"
        font-family="Arial, Helvetica, sans-serif" font-style="italic">Click a section to select seats</text>
</svg>`;

/**
 * Overview SVG for the Nested Groups example.
 *
 * Same layout as flat IDs — the overview SVG is mode-agnostic.
 * Section element IDs must match: orchestra, balcony.
 */
export const EXAMPLE_OVERVIEW_SVG_NESTED_GROUPS = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 620 420">
  <!-- Stage -->
  <rect x="110" y="20" width="400" height="60" rx="30" fill="#e2e8f0" stroke="#94a3b8" stroke-width="1.5"/>
  <text x="310" y="57" text-anchor="middle" fill="#64748b" font-size="18" font-family="Arial, Helvetica, sans-serif" font-weight="600">STAGE</text>

  <!-- Orchestra section area (clickable) -->
  <rect id="orchestra" x="130" y="110" width="360" height="110" rx="12"
        fill="#ede9fe" stroke="#a5b4fc" stroke-width="2" data-category="vip"/>
  <text x="310" y="160" text-anchor="middle" fill="#5b21b6" font-size="20"
        font-family="Arial, Helvetica, sans-serif" font-weight="600" pointer-events="none">ORCHESTRA</text>
  <text x="310" y="185" text-anchor="middle" fill="#7c3aed" font-size="13"
        font-family="Arial, Helvetica, sans-serif" pointer-events="none">VIP \u2014 21 seats</text>

  <!-- Aisle -->
  <line x1="130" y1="240" x2="490" y2="240" stroke="#cbd5e1" stroke-width="1" stroke-dasharray="6 4"/>

  <!-- Balcony section area (clickable) -->
  <rect id="balcony" x="100" y="260" width="420" height="110" rx="12"
        fill="#d1fae5" stroke="#86efac" stroke-width="2" data-category="standard"/>
  <text x="310" y="310" text-anchor="middle" fill="#065f46" font-size="20"
        font-family="Arial, Helvetica, sans-serif" font-weight="600" pointer-events="none">BALCONY</text>
  <text x="310" y="335" text-anchor="middle" fill="#047857" font-size="13"
        font-family="Arial, Helvetica, sans-serif" pointer-events="none">Standard \u2014 27 seats</text>

  <!-- Instruction -->
  <text x="310" y="400" text-anchor="middle" fill="#94a3b8" font-size="11"
        font-family="Arial, Helvetica, sans-serif" font-style="italic">Click a section to select seats</text>
</svg>`;

/**
 * Trigger a file download from a string.
 *
 * @param {string} content  File content.
 * @param {string} filename Suggested file name.
 * @param {string} mime     MIME type.
 */
export function downloadStringAsFile( content, filename, mime = 'image/svg+xml' ) {
	const blob = new Blob( [ content ], { type: mime } );
	const url = URL.createObjectURL( blob );
	const a = document.createElement( 'a' );
	a.href = url;
	a.download = filename;
	document.body.appendChild( a );
	a.click();

	// Clean up.
	setTimeout( () => {
		document.body.removeChild( a );
		URL.revokeObjectURL( url );
	}, 100 );
}
