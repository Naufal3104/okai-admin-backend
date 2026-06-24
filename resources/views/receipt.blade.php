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
    @if(session('error'))
        <div class="no-print" style="max-width: 800px; margin: 20px auto; padding: 15px; background-color: #fde8e8; border: 1px solid #f8b4b4; color: #9b1c1c; border-radius: 8px; font-weight: bold; font-family: Arial, sans-serif;">
            {{ session('error') }}
        </div>
    @endif

    @if(session('success'))
        <div class="no-print" style="max-width: 800px; margin: 20px auto; padding: 15px; background-color: #def7ec; border: 1px solid #bcf0da; color: #03543f; border-radius: 8px; font-weight: bold; font-family: Arial, sans-serif;">
            {{ session('success') }}
        </div>
    @endif

    <div class="no-print" style="max-width: 800px; margin: 20px auto; display: flex; justify-content: space-between; align-items: center; font-family: Arial, sans-serif;">
        <div>
            <a href="http://localhost:3000/affiliate" style="text-decoration: none; font-weight: bold; color: #555; font-size: 14px;">&larr; Kembali ke Admin</a>
        </div>
        <div style="display: flex; gap: 10px;">
            @if ($withdrawal->status === 'approved')
                <form action="/affiliate/receipt/{{ $withdrawal->id }}/pay" method="POST" style="margin: 0;">
                    @csrf
                    <button type="submit" style="padding: 10px 20px; cursor: pointer; background: #28a745; color: #fff; border: none; font-weight: bold; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">Tandai Sudah Dibayar</button>
                </form>
            @endif
            <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer; background: #007bff; color: #fff; border: none; font-weight: bold; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">Cetak / Download PDF</button>
        </div>
    </div>
    
    <div class="container">
        <div class="header">
            <h1>BUKTI TANDA TERIMA PENCAIRAN KOMISI</h1>
            <p>ID Request: REQ-{{ str_pad($withdrawal->id, 5, '0', STR_PAD_LEFT) }}</p>
            <div style="margin-top: 10px; font-weight: bold; text-transform: uppercase;">
                Status: 
                @if($withdrawal->status === 'paid')
                    <span style="color: #28a745;">Sudah Dibayar (PAID)</span>
                @else
                    <span style="color: #ffc107;">Disetujui (APPROVED)</span>
                @endif
            </div>
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
                    <td>
                        @php
                            $bankName = ($withdrawal->bank_name && $withdrawal->bank_name !== '-') 
                                ? $withdrawal->bank_name 
                                : ($withdrawal->affiliate->bank_name ?? '-');
                                
                            $accountNumber = ($withdrawal->account_number && $withdrawal->account_number !== '-') 
                                ? $withdrawal->account_number 
                                : ($withdrawal->affiliate->account_number ?? '-');
                                
                            $accountHolder = ($withdrawal->affiliate->account_holder_name ?? null) 
                                ? $withdrawal->affiliate->account_holder_name 
                                : (($withdrawal->account_name && $withdrawal->account_name !== '-') ? $withdrawal->account_name : '');
                        @endphp
                        : {{ $bankName }} - {{ $accountNumber }}{{ $accountHolder ? " (a.n. $accountHolder)" : "" }}
                    </td>
                </tr>
                <tr>
                    <th>Tanggal Request</th>
                    <td>: {{ $withdrawal->created_at->format('d F Y H:i') }}</td>
                </tr>
                <tr>
                    <th>Tanggal Diupdate</th>
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