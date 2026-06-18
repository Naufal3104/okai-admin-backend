<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tanda Terima Pencairan Komisi</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            color: #333;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            border: 1px solid #ddd;
            padding: 40px;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        .details {
            margin-bottom: 30px;
        }
        .details th {
            text-align: left;
            padding-right: 20px;
        }
        .amount {
            font-size: 20px;
            font-weight: bold;
            text-align: center;
            padding: 20px;
            background-color: #f9f9f9;
            border: 1px dashed #ccc;
            margin-bottom: 30px;
        }
        .signatures {
            display: flex;
            justify-content: space-between;
            margin-top: 50px;
        }
        .signature-box {
            text-align: center;
            width: 200px;
        }
        .signature-line {
            margin-top: 60px;
            border-bottom: 1px solid #333;
        }
        @media print {
            body {
                background: white;
            }
            .container {
                border: none;
                padding: 0;
            }
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="no-print" style="text-align: right; margin-bottom: 20px;">
        <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer; background: #333; color: #fff; border: none; font-weight: bold;">Cetak / Download PDF</button>
    </div>
    
    <div class="container">
        <div class="header">
            <h1>BUKTI TANDA TERIMA PENCAIRAN KOMISI</h1>
            <p>ID Request: REQ-{{ str_pad($withdrawal->id, 5, '0', STR_PAD_LEFT) }}</p>
        </div>

        <div class="details">
            <p>Telah diserahkan komisi program affiliate kepada:</p>
            <table>
                <tr>
                    <th>Nama Afiliator</th>
                    <td>: {{ $withdrawal->affiliate->user->name ?? '-' }}</td>
                </tr>
                <tr>
                    <th>Kode Referral</th>
                    <td>: {{ $withdrawal->affiliate->affiliate_code ?? '-' }}</td>
                </tr>
                <tr>
                    <th>Bank / Rekening</th>
                    <td>: {{ $withdrawal->affiliate->bank_name ?? '-' }} - {{ $withdrawal->affiliate->bank_account_number ?? '-' }}</td>
                </tr>
                <tr>
                    <th>Tanggal Disetujui</th>
                    <td>: {{ $withdrawal->updated_at->format('d F Y H:i') }}</td>
                </tr>
            </table>
        </div>

        <div class="amount">
            Total Pencairan: Rp {{ number_format($withdrawal->amount, 0, ',', '.') }}
        </div>

        <div class="signatures">
            <div class="signature-box">
                <p>Pihak Manajemen,</p>
                <div class="signature-line"></div>
                <p>Okai Store Admin</p>
            </div>
            <div class="signature-box">
                <p>Penerima,</p>
                <div class="signature-line"></div>
                <p>{{ $withdrawal->affiliate->user->name ?? 'Afiliator' }}</p>
            </div>
        </div>
    </div>
</body>
</html>