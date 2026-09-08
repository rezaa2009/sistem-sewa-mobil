<?php
// modules/laporan/dashboard.php
// Dashboard - Sistem Penyewaan Mobil
require_once __DIR__ . '/../../config/konstanta.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_middleware.php';
cek_login();

// --- Ringkasan angka ---
$total_mobil          = $pdo->query("SELECT COUNT(*) c FROM tb_alat WHERE status='aktif'")->fetch()['c'];
$unit_tersedia        = $pdo->query("SELECT COALESCE(SUM(stok_tersedia),0) c FROM tb_alat")->fetch()['c'];
$transaksi_berjalan   = $pdo->query("SELECT COUNT(*) c FROM tb_transaksi_sewa WHERE status='berjalan'")->fetch()['c'];
$transaksi_terlambat  = $pdo->query(
    "SELECT COUNT(*) c FROM tb_transaksi_sewa WHERE status='berjalan' AND tanggal_rencana_kembali < CURDATE()"
)->fetch()['c'];
$pendapatan_bulan_ini = $pdo->query(
    "SELECT COALESCE(SUM(total_pembayaran),0) c FROM tb_pengembalian
     WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())"
)->fetch()['c'];

// --- Utilisasi armada (unit yang sedang disewa vs total unit) ---
$unit_disewa      = max(0, (int) $transaksi_berjalan);
$total_armada     = (int) $unit_tersedia + $unit_disewa;
$persen_utilisasi = $total_armada > 0 ? round(($unit_disewa / $total_armada) * 100) : 0;

// --- Transaksi yang perlu perhatian ---
$transaksi_perhatian = $pdo->query(
    "SELECT t.*, p.nama_pelanggan
     FROM tb_transaksi_sewa t
     JOIN tb_pelanggan p ON p.id_pelanggan = t.id_pelanggan
     WHERE t.status = 'berjalan'
     ORDER BY t.tanggal_rencana_kembali ASC
     LIMIT 8"
)->fetchAll();

// --- Mobil dengan unit menipis ---
$mobil_menipis = $pdo->query(
    "SELECT * FROM tb_alat WHERE status='aktif' AND stok_tersedia <= 2 ORDER BY stok_tersedia ASC LIMIT 5"
)->fetchAll();

$judul_halaman = 'Dashboard';
require_once __DIR__ . '/../../includes/header.php';

// Nilai gauge dibatasi 0-100 untuk keperluan tampilan conic-gradient
$gauge_deg = max(0, min(100, (int) $persen_utilisasi)) * 3.6;
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

<style>
    .dash-scope {
        --bg: #10131a;
        --panel: #171b23;
        --panel-line: rgba(255,255,255,.07);
        --ink: #e7ebf2;
        --ink-dim: #838da0;
        --amber: #f5a623;
        --teal: #35c7bd;
        --red: #ef5350;
        --blue: #4f8dfd;
        font-family: 'Rajdhani', sans-serif;
        color: var(--ink);
        background: var(--bg);
        margin: -1.5rem -1.5rem 0;
        padding: 28px clamp(16px, 4vw, 40px) 48px;
    }
    .dash-scope .mono { font-family: 'IBM Plex Mono', monospace; }

    .dash-topbar {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        border-bottom: 1px solid var(--panel-line);
        padding-bottom: 16px;
        margin-bottom: 28px;
    }
    .dash-topbar h1 {
        font-weight: 700;
        font-size: 1.6rem;
        margin: 0;
        letter-spacing: .01em;
    }
    .dash-topbar .tanggal {
        color: var(--ink-dim);
        font-size: .9rem;
    }

    /* --- Klaster gauge segmen (4 angka ringkasan) --- */
    .segstat-row {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 14px;
        margin-bottom: 22px;
    }
    .segstat {
        background: var(--panel);
        border: 1px solid var(--panel-line);
        border-radius: 10px;
        padding: 16px 18px;
    }
    .segstat .segstat-label {
        color: var(--ink-dim);
        font-size: .82rem;
        margin-bottom: 10px;
    }
    .segstat .segstat-value {
        font-size: 2rem;
        font-weight: 600;
        line-height: 1;
        margin-bottom: 12px;
    }
    .segstat .ticks {
        display: flex;
        gap: 3px;
        height: 6px;
    }
    .segstat .ticks span {
        flex: 1;
        background: rgba(255,255,255,.09);
        border-radius: 2px;
    }
    .segstat .ticks span.lit { background: var(--seg-color, var(--blue)); }
    .segstat.c-total   { --seg-color: var(--blue); }
    .segstat.c-siap    { --seg-color: var(--teal); }
    .segstat.c-jalan   { --seg-color: var(--amber); }
    .segstat.c-telat   { --seg-color: var(--red); }
    .segstat.c-telat .segstat-value { color: var(--red); }

    /* --- Panel utama: gauge utilisasi + pendapatan --- */
    .cockpit {
        display: grid;
        grid-template-columns: 260px 1fr;
        gap: 0;
        background: var(--panel);
        border: 1px solid var(--panel-line);
        border-radius: 12px;
        overflow: hidden;
        margin-bottom: 22px;
    }
    .cockpit-gauge {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 26px 20px;
        border-right: 1px solid var(--panel-line);
    }
    .gauge-ring {
        width: 168px;
        height: 168px;
        border-radius: 50%;
        background: conic-gradient(var(--teal) <?= (float) $gauge_deg ?>deg, rgba(255,255,255,.08) 0deg);
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
    }
    .gauge-ring::before {
        content: "";
        position: absolute;
        width: 134px;
        height: 134px;
        border-radius: 50%;
        background: var(--panel);
    }
    .gauge-ring .gauge-readout {
        position: relative;
        text-align: center;
    }
    .gauge-readout .num {
        font-size: 2.1rem;
        font-weight: 600;
    }
    .gauge-readout .unit {
        font-size: .8rem;
        color: var(--ink-dim);
    }
    .cockpit-gauge .cap {
        margin-top: 14px;
        font-size: .85rem;
        color: var(--ink-dim);
        text-align: center;
    }
    .cockpit-gauge .cap b { color: var(--ink); font-weight: 600; }

    .cockpit-side {
        padding: 26px 30px;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }
    .cockpit-side .odometer-label {
        color: var(--ink-dim);
        font-size: .85rem;
        margin-bottom: 8px;
    }
    .cockpit-side .odometer {
        font-size: 2.6rem;
        font-weight: 600;
        color: var(--teal);
        letter-spacing: .02em;
        margin-bottom: 4px;
    }
    .cockpit-side .odometer-note {
        color: var(--ink-dim);
        font-size: .82rem;
    }

    /* --- Panel tabel (log) --- */
    .panels-row {
        display: grid;
        grid-template-columns: 1.5fr 1fr;
        gap: 16px;
    }
    .panel {
        background: var(--panel);
        border: 1px solid var(--panel-line);
        border-radius: 12px;
        overflow: hidden;
    }
    .panel .panel-title {
        padding: 14px 18px;
        border-bottom: 1px solid var(--panel-line);
        font-weight: 600;
        font-size: .95rem;
    }
    .log-row {
        display: grid;
        grid-template-columns: 4px 1fr auto;
        align-items: center;
        gap: 14px;
        padding: 11px 18px 11px 0;
        border-bottom: 1px solid var(--panel-line);
    }
    .log-row:last-child { border-bottom: none; }
    .log-row .bar { align-self: stretch; border-radius: 2px; background: var(--blue); }
    .log-row.telat .bar { background: var(--red); }
    .log-row .info .kode {
        font-weight: 600;
        text-decoration: none;
        color: var(--ink);
    }
    .log-row .info .kode:hover { color: var(--teal); }
    .log-row .info .meta {
        color: var(--ink-dim);
        font-size: .82rem;
        margin-top: 2px;
    }
    .log-row .status {
        font-size: .78rem;
        text-align: right;
        white-space: nowrap;
    }
    .log-row.telat .status { color: var(--red); }
    .log-row:not(.telat) .status { color: var(--amber); }

    .empty-note {
        padding: 26px 18px;
        text-align: center;
        color: var(--ink-dim);
        font-size: .88rem;
    }

    .stock-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 11px 18px;
        border-bottom: 1px solid var(--panel-line);
        font-size: .92rem;
    }
    .stock-row:last-child { border-bottom: none; }
    .stock-row .sisa { color: var(--red); font-weight: 600; }

    .dash-actions { margin-top: 20px; }
    .btn-cetak {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: transparent;
        border: 1px solid var(--panel-line);
        color: var(--ink);
        padding: 9px 16px;
        border-radius: 8px;
        font-size: .88rem;
        text-decoration: none;
        transition: border-color .15s ease;
    }
    .btn-cetak:hover { border-color: var(--teal); color: var(--teal); }

    @media (max-width: 900px) {
        .segstat-row { grid-template-columns: repeat(2, 1fr); }
        .cockpit { grid-template-columns: 1fr; }
        .cockpit-gauge { border-right: none; border-bottom: 1px solid var(--panel-line); }
        .panels-row { grid-template-columns: 1fr; }
    }
</style>

<div class="dash-scope">

    <div class="dash-topbar">
        <h1>Dashboard armada</h1>
        <div class="tanggal mono"><?= h(date('d.m.Y')) ?></div>
    </div>

    <div class="segstat-row">
        <div class="segstat c-total">
            <div class="segstat-label">Mobil aktif</div>
            <div class="segstat-value mono"><?= (int) $total_mobil ?></div>
            <div class="ticks">
                <?php for ($i = 0; $i < 10; $i++): ?>
                    <span class="<?= $i < min(10, $total_mobil) ? 'lit' : '' ?>"></span>
                <?php endfor; ?>
            </div>
        </div>
        <div class="segstat c-siap">
            <div class="segstat-label">Unit siap disewa</div>
            <div class="segstat-value mono"><?= (int) $unit_tersedia ?></div>
            <div class="ticks">
                <?php for ($i = 0; $i < 10; $i++): ?>
                    <span class="<?= $i < min(10, $unit_tersedia) ? 'lit' : '' ?>"></span>
                <?php endfor; ?>
            </div>
        </div>
        <div class="segstat c-jalan">
            <div class="segstat-label">Sedang disewa</div>
            <div class="segstat-value mono"><?= (int) $transaksi_berjalan ?></div>
            <div class="ticks">
                <?php for ($i = 0; $i < 10; $i++): ?>
                    <span class="<?= $i < min(10, $transaksi_berjalan) ? 'lit' : '' ?>"></span>
                <?php endfor; ?>
            </div>
        </div>
        <div class="segstat c-telat">
            <div class="segstat-label">Terlambat kembali</div>
            <div class="segstat-value mono"><?= (int) $transaksi_terlambat ?></div>
            <div class="ticks">
                <?php for ($i = 0; $i < 10; $i++): ?>
                    <span class="<?= $i < min(10, $transaksi_terlambat) ? 'lit' : '' ?>"></span>
                <?php endfor; ?>
            </div>
        </div>
    </div>

    <div class="cockpit">
        <div class="cockpit-gauge">
            <div class="gauge-ring">
                <div class="gauge-readout">
                    <div class="num mono"><?= (int) $persen_utilisasi ?>%</div>
                    <div class="unit">utilisasi</div>
                </div>
            </div>
            <div class="cap"><b><?= (int) $unit_disewa ?></b> dari <b><?= (int) $total_armada ?></b> unit armada sedang di jalan</div>
        </div>
        <div class="cockpit-side">
            <div class="odometer-label">Pendapatan bulan ini</div>
            <div class="odometer mono"><?= format_rupiah($pendapatan_bulan_ini) ?></div>
            <div class="odometer-note">Dihitung dari transaksi pengembalian yang sudah selesai, bulan berjalan.</div>
        </div>
    </div>

    <div class="panels-row">
        <div class="panel">
            <div class="panel-title">Transaksi perlu perhatian</div>
            <?php if (empty($transaksi_perhatian)): ?>
                <div class="empty-note">Tidak ada transaksi berjalan saat ini.</div>
            <?php endif; ?>
            <?php foreach ($transaksi_perhatian as $t): ?>
                <?php $telat = strtotime($t['tanggal_rencana_kembali']) < strtotime(date('Y-m-d')); ?>
                <div class="log-row <?= $telat ? 'telat' : '' ?>">
                    <div class="bar"></div>
                    <div class="info">
                        <a href="../transaksi/detail.php?id=<?= (int) $t['id_transaksi'] ?>" class="kode mono"><?= h($t['kode_transaksi']) ?></a>
                        <div class="meta"><?= h($t['nama_pelanggan']) ?> &middot; kembali <?= format_tanggal($t['tanggal_rencana_kembali']) ?></div>
                    </div>
                    <div class="status"><?= $telat ? 'Terlambat' : 'Berjalan' ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="panel">
            <div class="panel-title">Unit mobil menipis</div>
            <?php if (empty($mobil_menipis)): ?>
                <div class="empty-note">Semua unit armada aman.</div>
            <?php endif; ?>
            <?php foreach ($mobil_menipis as $a): ?>
                <div class="stock-row">
                    <span><?= h($a['nama_alat']) ?></span>
                    <span class="sisa mono"><?= (int) $a['stok_tersedia'] ?> unit</span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="dash-actions">
        <a href="cetak_pdf.php" class="btn-cetak" target="_blank">Cetak laporan transaksi (PDF)</a>
    </div>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>