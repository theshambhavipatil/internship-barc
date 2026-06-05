<?php
date_default_timezone_set('Asia/Kolkata');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pathology Report Portal | BARC Hospital</title>
    
    <style>
        :root {
            --primary-color: #0056b3;
            --primary-hover: #004494;
            --bg-color: #f0f2f5;
            --card-bg: #ffffff;
            --text-dark: #1e293b;
            --text-light: #64748b;
            --border-color: #e2e8f0;
            --focus-ring: rgba(0, 86, 179, 0.2);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            /* USE SYSTEM FONTS (Zero Internet Required) */
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
            background-color: var(--bg-color);
            background-image: radial-gradient(#e5e7eb 1px, transparent 1px);
            background-size: 20px 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            color: var(--text-dark);
            padding: 20px;
        }

        .container { width: 100%; max-width: 440px; }

        .card {
            background: var(--card-bg);
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            border-top: 5px solid var(--primary-color);
        }

        .header { text-align: center; margin-bottom: 32px; }
        .header h1 { font-size: 22px; font-weight: 600; color: var(--text-dark); margin-bottom: 8px; }
        .header p { font-size: 14px; color: var(--text-light); }

        .form-group { margin-bottom: 20px; }
        
        label { display: block; font-size: 13px; font-weight: 500; color: var(--text-dark); margin-bottom: 8px; }
        
        input {
            width: 100%; padding: 12px 14px; font-size: 15px;
            color: var(--text-dark); background: #fff;
            border: 1px solid var(--border-color); border-radius: 6px;
            transition: all 0.2s ease; font-family: inherit;
        }
        input:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 3px var(--focus-ring); }

        .date-row { display: flex; gap: 15px; }
        .date-row .form-group { flex: 1; }

        button {
            width: 100%; padding: 14px; font-size: 15px; font-weight: 600;
            color: white; background-color: var(--primary-color);
            border: none; border-radius: 6px; cursor: pointer;
            transition: background-color 0.2s;
            display: flex; justify-content: center; align-items: center; gap: 10px;
        }
        button:hover { background-color: var(--primary-hover); }
        button:disabled { opacity: 0.7; cursor: not-allowed; }

        /* Spinner (CSS only, no images needed) */
        .spinner {
            display: none; width: 18px; height: 18px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%; border-top-color: #fff;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .footer {
            margin-top: 24px; text-align: center; font-size: 12px;
            color: var(--text-light); border-top: 1px solid var(--border-color);
            padding-top: 20px;
        }

        @media (max-width: 480px) {
            .card { padding: 25px; }
            .date-row { flex-direction: column; gap: 0; }
        }
    </style>
</head>
<body>

    <div class="container">
        <div class="card">
            <div class="header">
                <h1>Lab Report Portal</h1>
                <p>Medical Division · BARC Hospital</p>
            </div>

            <form id="reportForm" method="POST" action="nabl_report1.php" target="_blank">
                <div class="form-group">
                    <label for="mrd">MRD Number</label>
                    <input type="text" id="mrd" name="mrd" placeholder="e.g.  XX/XXXXX" required autocomplete="off">
                </div>

                <div class="date-row">
                    <div class="form-group">
                        <label for="from_date">From Date</label>
                        <input type="date" id="from_date" name="from_date" required>
                    </div>

                    <div class="form-group">
                        <label for="to_date">To Date</label>
                        <input type="date" id="to_date" name="to_date" required>
                    </div>
                </div>

                <button type="submit" id="submitBtn">
                    <span class="spinner" id="spinner"></span>
                    <span id="btnText">Generate Report</span>
                </button>
            </form>

            <div class="footer">
                Authorized Access Only · Pathology Laboratory
            </div>
        </div>
    </div>

    <script>
        // 1. Handle Submit: Show Spinner
        document.getElementById('reportForm').addEventListener('submit', function() {
            var btn = document.getElementById('submitBtn');
            var spinner = document.getElementById('spinner');
            var text = document.getElementById('btnText');

            // Use timeout to allow form submit to trigger first
            setTimeout(function() {
                // For PDF downloads opening in new tab, we usually keep button active 
                // or re-enable it quickly. Since we added target="_blank" above, 
                // let's re-enable it after 2 seconds so they can fix errors if needed.
                btn.disabled = true; 
                spinner.style.display = "block";
                text.innerText = "Generating...";

                setTimeout(function(){
                    btn.disabled = false;
                    spinner.style.display = "none";
                    text.innerText = "Generate Report";
                }, 3000); // Re-enable after 3 seconds
            }, 10);
        });
    </script>

</body>
</html>