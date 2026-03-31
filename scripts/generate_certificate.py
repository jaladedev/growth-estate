import sys
import json
import io
import qrcode
from reportlab.lib.pagesizes import A4
from reportlab.lib import colors
from reportlab.lib.units import mm
from reportlab.pdfgen import canvas
from reportlab.lib.utils import ImageReader

W, H = A4  # 595 x 842 pt


def make_qr(data: str) -> ImageReader:
    qr = qrcode.QRCode(
        version=2,
        box_size=6,
        border=2,
        error_correction=qrcode.constants.ERROR_CORRECT_H,
    )
    qr.add_data(data)
    qr.make(fit=True)
    img = qr.make_image(fill_color="#0D1F1A", back_color="white")
    buf = io.BytesIO()
    img.save(buf, format="PNG")
    buf.seek(0)
    return ImageReader(buf)


def draw_cert(d: dict, output_path: str) -> None:
    c = canvas.Canvas(output_path, pagesize=A4)
    c.setTitle(f"Certificate of Investment — {d['cert_number']}")
    c.setAuthor("SproutVest Technologies Ltd")
    c.setSubject("Digital Investment Certificate")

    # ── Background ──────────────────────────────────────────────────────────
    c.setFillColor(colors.HexColor("#0D1F1A"))
    c.rect(0, 0, W, H, fill=1, stroke=0)

    # ── Subtle dot-grid texture (cosmetic) ──────────────────────────────────
    c.setFillColor(colors.HexColor("#FFFFFF"))
    c.setFillAlpha(0.025)
    dot = 1.2
    gap = 22
    for x in range(int(18*mm), int(W - 16*mm), gap):
        for y in range(int(18*mm), int(H - 16*mm), gap):
            c.circle(x, y, dot, fill=1, stroke=0)

    c.setFillAlpha(1)

    # ── Outer gold border ───────────────────────────────────────────────────
    c.setStrokeColor(colors.HexColor("#C8873A"))
    c.setLineWidth(2.5)
    c.rect(14*mm, 14*mm, W - 28*mm, H - 28*mm, fill=0, stroke=1)

    # ── Inner thin border ───────────────────────────────────────────────────
    c.setStrokeColor(colors.HexColor("#C8873A"))
    c.setLineWidth(0.5)
    c.setStrokeAlpha(0.5)
    c.rect(17.5*mm, 17.5*mm, W - 35*mm, H - 35*mm, fill=0, stroke=1)
    c.setStrokeAlpha(1)

    # ── Header band ─────────────────────────────────────────────────────────
    c.setFillColor(colors.HexColor("#091510"))
    c.rect(17*mm, H - 64*mm, W - 34*mm, 47*mm, fill=1, stroke=0)

    # ── Top gold accent line ─────────────────────────────────────────────────
    c.setFillColor(colors.HexColor("#C8873A"))
    c.rect(17*mm, H - 19.5*mm, W - 34*mm, 1.5*mm, fill=1, stroke=0)

    # ── Corner ornaments ─────────────────────────────────────────────────────
    for cx, cy in [(17*mm, H-17*mm), (W-17*mm, H-17*mm),
                   (17*mm, 17*mm),   (W-17*mm, 17*mm)]:
        c.setFillColor(colors.HexColor("#C8873A"))
        c.setFillAlpha(0.6)
        c.circle(cx, cy, 2.5*mm, fill=1, stroke=0)
    c.setFillAlpha(1)

    # ── Brand name ───────────────────────────────────────────────────────────
    c.setFont("Helvetica-Bold", 11)
    c.setFillColor(colors.HexColor("#C8873A"))
    c.drawCentredString(W/2, H - 31*mm, "SPROUTVEST")

    # ── Title ────────────────────────────────────────────────────────────────
    c.setFont("Helvetica-Bold", 20)
    c.setFillColor(colors.HexColor("#FFFFFF"))
    c.drawCentredString(W/2, H - 44*mm, "CERTIFICATE OF INVESTMENT")

    # ── Subtitle ─────────────────────────────────────────────────────────────
    c.setFont("Helvetica", 8)
    c.setFillColor(colors.HexColor("#C8873A"))
    c.setFillAlpha(0.8)
    c.drawCentredString(W/2, H - 53*mm, "FRACTIONAL LAND INVESTMENT  ·  VERIFIED DIGITAL CERTIFICATE")
    c.setFillAlpha(1)

    # ── Decorative divider ───────────────────────────────────────────────────
    def gold_divider(y, alpha=0.5, width=0.7):
        c.setStrokeColor(colors.HexColor("#C8873A"))
        c.setStrokeAlpha(alpha)
        c.setLineWidth(width)
        c.line(34*mm, y, W - 34*mm, y)
        c.setStrokeAlpha(1)

    gold_divider(H - 71*mm, alpha=0.8, width=0.9)

    # ── "This is to certify that" ────────────────────────────────────────────
    c.setFont("Helvetica-Oblique", 9.5)
    c.setFillColor(colors.HexColor("#FFFFFF"))
    c.setFillAlpha(0.45)
    c.drawCentredString(W/2, H - 82*mm, "This is to certify that")
    c.setFillAlpha(1)

    # ── Owner name ───────────────────────────────────────────────────────────
    c.setFont("Helvetica-Bold", 24)
    c.setFillColor(colors.HexColor("#E8A850"))
    c.drawCentredString(W/2, H - 96*mm, d["owner_name"].upper())

    # ── "is the registered holder of" ────────────────────────────────────────
    c.setFont("Helvetica-Oblique", 9.5)
    c.setFillColor(colors.HexColor("#FFFFFF"))
    c.setFillAlpha(0.45)
    c.drawCentredString(W/2, H - 107*mm, "is the registered holder of")
    c.setFillAlpha(1)

    # ── Unit count ───────────────────────────────────────────────────────────
    c.setFont("Helvetica-Bold", 32)
    c.setFillColor(colors.HexColor("#FFFFFF"))
    c.drawCentredString(W/2, H - 122*mm, f"{int(d['units']):,} UNITS")

    # ── "in" ─────────────────────────────────────────────────────────────────
    c.setFont("Helvetica-Oblique", 9.5)
    c.setFillColor(colors.HexColor("#FFFFFF"))
    c.setFillAlpha(0.45)
    c.drawCentredString(W/2, H - 131*mm, "in")
    c.setFillAlpha(1)

    # ── Property title ────────────────────────────────────────────────────────
    c.setFont("Helvetica-Bold", 13)
    c.setFillColor(colors.HexColor("#C8873A"))
    c.drawCentredString(W/2, H - 142*mm, d["property_title"])

    # ── Property location ─────────────────────────────────────────────────────
    c.setFont("Helvetica", 8.5)
    c.setFillColor(colors.HexColor("#FFFFFF"))
    c.setFillAlpha(0.38)
    c.drawCentredString(W/2, H - 150*mm, d["property_location"])
    c.setFillAlpha(1)

    gold_divider(H - 158*mm, alpha=0.35, width=0.5)

    # ── Details grid (4 columns, 2 rows) ──────────────────────────────────────
    details = [
        ("CERTIFICATE NO.",  d["cert_number"]),
        ("LAND REFERENCE",   d["plot_identifier"] or "—"),
        ("TENURE TYPE",      d["tenure"] or "—"),
        ("PURCHASE REF",     d["purchase_reference"]),
        ("TOTAL INVESTED",   f"NGN {float(d['total_invested']):,.2f}"),
        ("ISSUE DATE",       d["issue_date"]),
        ("LGA",              d["lga"] or "—"),
        ("STATE",            d["state"] or "—"),
    ]

    # Two columns
    col_x   = [28*mm, W/2 + 5*mm]
    start_y = H - 170*mm
    row_h   = 14*mm

    for i, (label, val) in enumerate(details):
        col = i % 2
        row = i // 2
        x   = col_x[col]
        y   = start_y - row * row_h

        c.setFont("Helvetica-Bold", 6.5)
        c.setFillColor(colors.HexColor("#C8873A"))
        c.setFillAlpha(0.75)
        c.drawString(x, y, label)
        c.setFillAlpha(1)

        c.setFont("Helvetica", 8.5)
        c.setFillColor(colors.HexColor("#FFFFFF"))
        c.setFillAlpha(0.82)
        c.drawString(x, y - 5.5*mm, val)
        c.setFillAlpha(1)

    gold_divider(H - 228*mm, alpha=0.3, width=0.4)

    # ── QR code ──────────────────────────────────────────────────────────────
    qr_size = 30*mm
    qr_x    = W/2 - qr_size/2
    qr_y    = 44*mm

    # White box behind QR
    c.setFillColor(colors.HexColor("#FFFFFF"))
    c.roundRect(qr_x - 2.5*mm, qr_y - 2.5*mm,
                qr_size + 5*mm, qr_size + 5*mm,
                2*mm, fill=1, stroke=0)

    qr_img = make_qr(d["verify_url"])
    c.drawImage(qr_img, qr_x, qr_y, qr_size, qr_size, preserveAspectRatio=True)

    c.setFont("Helvetica", 6.5)
    c.setFillColor(colors.HexColor("#FFFFFF"))
    c.setFillAlpha(0.3)
    c.drawCentredString(W/2, 39*mm, "Scan to verify authenticity")
    c.setFillAlpha(1)

    # ── Digital signature ─────────────────────────────────────────────────────
    sig_display = d["signature"][:48] + "..."
    c.setFont("Helvetica", 5.8)
    c.setFillColor(colors.HexColor("#C8873A"))
    c.setFillAlpha(0.38)
    c.drawCentredString(W/2, 32*mm, f"Digital Signature: {sig_display}")
    c.setFillAlpha(1)

    # ── Footer ────────────────────────────────────────────────────────────────
    c.setFont("Helvetica", 7)
    c.setFillColor(colors.HexColor("#FFFFFF"))
    c.setFillAlpha(0.22)
    c.drawCentredString(W/2, 24*mm,
        "This certificate is digitally issued and verifiable at sproutvest.com/verify")

    c.setFont("Helvetica-Bold", 6.5)
    c.setFillColor(colors.HexColor("#C8873A"))
    c.setFillAlpha(0.45)
    c.drawCentredString(W/2, 19*mm,
        "SPROUTVEST TECHNOLOGIES LTD  ·  info@sproutvest.com")

    c.save()


if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: generate_certificate.py '<json>'", file=sys.stderr)
        sys.exit(1)

    try:
        data = json.loads(sys.argv[1])
        draw_cert(data, data["output_path"])
        print("OK")
    except Exception as e:
        print(f"ERROR: {e}", file=sys.stderr)
        sys.exit(1)
