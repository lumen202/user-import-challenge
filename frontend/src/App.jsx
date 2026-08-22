import { useState } from 'react';

/**
 * One-page import flow: Upload -> Preview -> Importing -> Result.
 *
 * The preview is display-only: the import request re-uploads the original
 * file and the server re-validates it from scratch. All counts shown come
 * straight from the API payload - the UI never recomputes them.
 */
export default function App() {
  const [phase, setPhase] = useState('upload'); // upload | preview | importing | result
  const [file, setFile] = useState(null);
  const [report, setReport] = useState(null);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  async function callApi(endpoint) {
    const body = new FormData();
    body.append('file', file);
    const response = await fetch(endpoint, { method: 'POST', body });
    let payload = null;
    try {
      payload = await response.json();
    } catch {
      // Non-JSON response (proxy down, PHP fatal): fall through to generic error.
    }
    if (!response.ok) {
      throw new Error(payload?.error ?? `Request failed (HTTP ${response.status})`);
    }
    return payload;
  }

  async function validate() {
    setBusy(true);
    setError(null);
    try {
      setReport(await callApi('/api/validate'));
      setPhase('preview');
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }

  async function doImport() {
    setBusy(true);
    setError(null);
    setPhase('importing');
    try {
      setReport(await callApi('/api/import'));
      setPhase('result');
    } catch (e) {
      setError(e.message);
      setPhase('preview');
    } finally {
      setBusy(false);
    }
  }

  function reset() {
    setPhase('upload');
    setFile(null);
    setReport(null);
    setError(null);
  }

  return (
    <main className="app">
      <h1>User import</h1>
      <p className="tagline">Upload a CSV of users (name, surname, email), review it, import it.</p>

      {error && <div className="banner error" role="alert">{error}</div>}

      {phase === 'upload' && (
        <section className="card">
          <input
            type="file"
            accept=".csv,text/csv"
            onChange={(e) => setFile(e.target.files[0] ?? null)}
          />
          <button onClick={validate} disabled={!file || busy}>
            {busy ? 'Checking…' : 'Validate file'}
          </button>
        </section>
      )}

      {(phase === 'preview' || phase === 'importing' || phase === 'result') && report && (
        <>
          <section className="summary">
            <div className="stat"><strong>{report.found}</strong> rows found</div>
            <div className="stat ok"><strong>{report.valid}</strong> valid</div>
            <div className="stat bad"><strong>{report.invalid}</strong> invalid</div>
            {phase === 'result' && (
              <>
                <div className="stat ok"><strong>{report.inserted}</strong> inserted</div>
                <div className="stat"><strong>{report.skippedExisting}</strong> already existed</div>
              </>
            )}
          </section>

          {phase === 'preview' && (
            <section className="actions">
              <button onClick={doImport} disabled={report.valid === 0 || busy}>
                Import {report.valid} user{report.valid === 1 ? '' : 's'}
              </button>
              <button className="secondary" onClick={reset} disabled={busy}>
                Choose another file
              </button>
            </section>
          )}

          {phase === 'importing' && <p className="importing">Importing…</p>}

          {phase === 'result' && (
            <section className="actions">
              <p className="done">Import complete.</p>
              <button className="secondary" onClick={reset}>Start over</button>
            </section>
          )}

          <table>
            <thead>
              <tr><th>Line</th><th>Name</th><th>Surname</th><th>Email</th><th>Status</th><th>Problem</th></tr>
            </thead>
            <tbody>
              {report.records.map((r) => (
                <tr key={r.line} className={r.status === 'valid' ? 'row-valid' : 'row-invalid'}>
                  <td>{r.line}</td>
                  <td>{r.name}</td>
                  <td>{r.surname}</td>
                  <td>{r.email}</td>
                  <td>{r.status}</td>
                  <td>{r.error ?? ''}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </>
      )}
    </main>
  );
}
