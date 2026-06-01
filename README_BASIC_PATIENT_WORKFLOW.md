# Basic Patient Workflow Guide (OSCAR / OpenOSP)

This guide walks through a **complete sample patient journey** in OSCAR/OpenOSP—from logging in to a final resolved visit. It is for **learning and local testing only**. It does not change any application code or settings.

If you are new to electronic medical record (EMR) systems, read each section in order. Menu names can vary slightly by clinic setup and Oscar version; look for areas described below (for example, “demographics,” “patient chart,” “documents,” “encounter,” or “case management notes”).

---

## Important: local development and training only

- Use this workflow only on a **local or training** OpenOSP environment.
- **Do not** enter real patient names, addresses, or health card numbers.
- **Do not** upload real medical documents or lab reports.
- Use **fake** demographics and **sample PDFs** created for testing (for example, files named like “Sample CBC Lab Result” or PDFs from your team’s sample-data tooling).
- Patient charts are usually **kept as history** after a case is resolved; they are not deleted from the system.

---

## 1. Prerequisites before using the system

Complete these steps before you practice the workflow.

### 1.1 Application is running

- Your OpenOSP Docker stack should be started (for example, after `./openosp start` from the project setup).
- You should be able to open Oscar in a browser, typically at **http://localhost:9080/oscar** (your URL may differ).

### 1.2 You can log in

- You need a valid **username**, **password**, and (if your site uses it) a **second-level PIN or passcode**.
- On a fresh local install, credentials are often provided in the main project README or by whoever ran database bootstrap (for example, a default doctor account such as `openodoc`).
- If login fails, see [Troubleshooting](#10-troubleshooting) before continuing.

### 1.3 A doctor/provider account exists

- Clinical work (notes, prescriptions, signing) is tied to a **provider** (doctor) record in the system.
- You may use an existing bootstrap provider or create a new one (Section 2).
- For this guide, you will use that provider when documenting visits and uploading documents.

### 1.4 A sample patient can be created

- You will register a **test patient** with fake information (Section 3).
- Alternatively, your environment may already include **sample patients** from development seed data (names often look like test prefixes). You can still follow this guide by using your own fake patient instead.

### 1.5 Sample PDF files are ready

Prepare **three small fake PDF files** on your computer before the upload step (Section 5), for example:

| File purpose | Suggested display name in Oscar |
|--------------|----------------------------------|
| Lab result | Sample CBC Lab Result |
| Referral | Sample Referral Letter |
| Imaging / diagnostic | Sample X-Ray Report |

These should be clearly fictional (watermarked “sample” or “training” text is ideal). You will attach them to the patient chart later.

---

## 2. Create a new doctor/provider

Use this section if you need a **second** doctor for training, or if your environment has no suitable provider besides the default login account.

### 2.1 Where to go

1. Log in with an account that has **administrative** privileges (often the main bootstrap doctor account).
2. Open the **administration** or **management** area of Oscar (sometimes labeled Admin, Administration, or similar).
3. Go to the section for **providers**, **doctors**, or **user/provider management** (wording varies).
4. Choose the option to **add** or **register** a new provider.

### 2.2 Minimum information to enter

Enter enough information to identify the doctor in the system. Typical minimum fields include:

- **Last name** and **first name** (for example, Santos, Maria)
- **Provider type** or role (for example, doctor / MD)
- **Provider number** or billing identifier, if the form requires one (for local testing, use a clearly fake value only if your setup allows placeholders)
- **Status** active (so the provider appears in lists)
- **Contact or clinic details** only if required by your form; otherwise optional for training

Save or update the record when the form is complete.

### 2.3 Login access (if applicable)

Some sites separate **clinical provider records** from **login accounts**:

- If your environment has **security** or **user login** management under administration, you may need to create a **login** linked to the new provider (username, password, PIN).
- Assign roles or permissions that allow **clinical documentation** and **document upload** for training.
- If you only have one bootstrap login, you can skip creating a separate login and still use the new provider record when selecting “author” on notes—depending on how your site is configured.

### 2.4 Use this doctor for the consultation

- When you create appointments and encounter notes (Sections 4–7), select **Dr. Maria Santos** (or your new provider) as the **attending / signing provider** where the application asks for it.
- This keeps the sample journey consistent in the chart.

---

## 3. Create or register a new patient

### 3.1 Where to go

1. From the main Oscar screen after login, go to the **patient registration** or **demographic** area (often “Search/Add Demographic,” “New Patient,” or “Demographics”).
2. Choose **add new patient** or **register new demographic**.

### 3.2 Basic demographics

Enter **fictional** information only, for example:

| Field | Example (fake) |
|-------|----------------|
| First name | Juan |
| Last name | Dela Cruz |
| Date of birth | A date that makes the patient an adult in your test scenario |
| Sex / gender | As required by the form |
| Chart number | Optional; use something like `TRAIN-001` if the field exists |

### 3.3 Contact information

Add sample contact details, for example:

- Address: a fake street and city
- Phone: a fake number (e.g. 555-0100)
- Email: a non-real address (e.g. `training@example.invalid`)

### 3.4 Health card or identifier

- If your region requires a **health card / PHN / HIN**, use a **placeholder test number** that is clearly not real (follow your local training policy; many teams use obviously invalid patterns for dev only).
- If the field is optional locally, you may leave it blank only if your environment allows it.

### 3.5 Assign a family doctor / roster provider (if shown)

- Set the **provider** or **MD** field to your training doctor (e.g. Dr. Maria Santos) if the form includes it.
- This helps the patient appear on that doctor’s lists and defaults.

### 3.6 Confirm the record is saved

1. Click **Save**, **Submit**, or **Update** on the demographic form.
2. Note any confirmation message or assigned **demographic / patient number**.
3. Search for the patient by **last name** to confirm they appear in search results.
4. Open the patient chart once to verify the demographic summary displays correctly.

---

## 4. First visit — patient comes in for initial consultation

This section covers the **first encounter** for cough and fever in the sample scenario.

### 4.1 Search and open the patient chart

1. Go to **patient search** (demographic search).
2. Search by **last name** (e.g. Dela Cruz) or chart number.
3. Open the **patient chart** or **master record** for Juan Dela Cruz.

### 4.2 Appointment (if your workflow uses scheduling)

Many clinics book an appointment before documenting the visit:

1. Open the **appointment** or **schedule** area (calendar, day sheet, or appointment module).
2. Create an appointment for today (or a test date) for patient **Juan Dela Cruz**.
3. Assign provider **Dr. Maria Santos**.
4. Set a time slot and reason such as “new cough” or “walk-in.”
5. Save the appointment.

If your training environment documents visits without appointments, you can open the chart directly and start a note from the clinical area.

### 4.3 Start a new encounter / consultation note

1. From the patient chart, open the **clinical documentation** area (often **encounter**, **case management**, **CPP/Rx**, or **notes** tab—labels vary).
2. Start a **new note** or **new encounter** for today’s visit.
3. Link the note to today’s **appointment** if the system prompts you to do so.

### 4.4 Reason for visit

In the note header or subjective section, record:

- **Chief complaint:** cough and fever
- **Duration:** for example, 3 days (fictional)

### 4.5 Symptoms and history

Add brief fictional details, for example:

- Dry cough, low-grade fever, no shortness of breath
- No known drug allergies (or document “NKDA” if that is your local convention)
- Past medical history: none significant for training

### 4.6 Vitals (if available)

If your screen has a **vitals** or **measurements** section, enter sample values, for example:

- Temperature: 38.1 °C
- Blood pressure: 118/76
- Pulse: 88
- Respiratory rate: 16

Save vitals if they are entered separately from the note.

### 4.7 Assessment / diagnosis

Document a **training diagnosis**, for example:

- **Assessment:** Acute upper respiratory infection (fictional for exercise)

Use wording appropriate to your region; this is not medical advice.

### 4.8 Plan / treatment

Add a simple plan, for example:

- Symptomatic care, fluids, rest
- Return if worsening shortness of breath or high fever
- Consider chest imaging if not improving (sets up later PDF upload)

### 4.9 Save and sign the note

1. **Save** the note.
2. If your site uses **signing** or **locking** notes, sign the note as Dr. Maria Santos when prompted.
3. Re-open the chart and confirm the note appears in the **note list** or **encounter history** for the correct date.

---

## 5. Upload patient PDF files

Attach the three sample PDFs you prepared in Section 1.5.

### 5.1 Where to go

1. Open **Juan Dela Cruz**’s chart again.
2. Go to the **documents** area for the patient (often **Documents**, **E-Chart documents**, **Labs/Docs**, or a document manager linked to the demographic).

### 5.2 Upload each PDF

For each file, use the **upload** or **add document** action:

| Order | File on your computer | Clear name in Oscar |
|-------|------------------------|---------------------|
| 1 | Fake CBC PDF | **Sample CBC Lab Result** |
| 2 | Fake referral PDF | **Sample Referral Letter** |
| 3 | Fake imaging PDF | **Sample X-Ray Report** |

When uploading:

- Choose document **type** that matches the content if the form asks (for example, **lab**, **consult/referral**, **radiology/imaging**—options depend on your site).
- Set the **description** to the clear name in the table above.
- Confirm the file is linked to **this patient** (correct demographic), not another chart.

### 5.3 Confirm files are visible

1. Return to the patient’s **document list** or document browser.
2. Verify all three documents appear with the correct titles and dates.
3. Open each document preview or download link to confirm the PDF opens.
4. If your site has an **inbox** or **provider lab/document routing** view, check whether new documents appear there and are marked reviewed when appropriate.

---

## 6. Follow-up visit — patient returns

Assume **Juan** returns a few days later with improving symptoms.

### 6.1 Open the same patient

1. Search again for **Dela Cruz, Juan**.
2. Open the full chart.

### 6.2 Review previous documentation

Before writing a new note:

1. Open the **first visit note** and read complaint, assessment, and plan.
2. Open each uploaded PDF:
   - Sample CBC Lab Result  
   - Sample X-Ray Report  
   - Sample Referral Letter  
3. Confirm results align with your fictional story (for training, you only need to see that they are accessible).

### 6.3 Create a new follow-up encounter note

1. Start a **new note** or **new encounter** for the follow-up date.
2. Link to a **follow-up appointment** if you use scheduling.

### 6.4 Updated symptoms

Document improvement, for example:

- Cough decreased, fever resolved
- Energy improving, no new complaints

### 6.5 Updated assessment

For example:

- Acute respiratory illness — improving

### 6.6 Medication / treatment changes (if applicable)

If your training includes prescribing:

- Note continued symptomatic care only, or
- Add a fictional medication change only if your instructor wants Rx practice

Otherwise, state “no medication changes” in the plan.

### 6.7 Next steps

For example:

- Continue rest and fluids
- Return in one week if symptoms recur
- No further imaging needed at this time (fictional)

### 6.8 Save and sign

Save and sign the follow-up note. Confirm it appears **below or after** the first visit note in the chart timeline.

---

## 7. Final visit — condition resolved

### 7.1 Open the patient chart

Search for **Juan Dela Cruz** and open the chart. Review:

- All consultation notes (first visit, follow-up)
- All three sample PDFs

### 7.2 Create the final consultation note

Start a **new note** for the final visit date. Document:

- Patient feels well; cough gone; no fever
- **Final assessment:** condition resolved (use your local terminology, e.g. resolved acute URI)
- **Discharge / advice:** return PRN (as needed) for new respiratory symptoms; routine care otherwise

### 7.3 Mark resolved (if the application supports it)

Some Oscar setups allow problem-list or encounter status flags:

- If you see **resolved**, **inactive problem**, or **close encounter**, apply it to the training problem you documented.
- If your training site does not expose that feature, stating “resolved” clearly in the final note is sufficient for this exercise.

### 7.4 Save and sign the final note

Save and sign. Verify three notes exist (first visit, follow-up, final).

### 7.5 Chart retention

- The patient **remains in the system** as historical record.
- Do **not** delete the demographic or documents for training unless your administrator instructs you to clean test data.
- Sample cleanup in development environments may be done with separate tooling; that is outside this workflow guide.

---

## 8. Sample scenario (end-to-end story)

Use this narrative to practice the full path:

| Item | Value |
|------|--------|
| **Doctor** | Dr. Maria Santos |
| **Patient** | Juan Dela Cruz |
| **First visit** | Complaint: cough and fever for several days; assessment: acute respiratory illness; plan: symptomatic care, possible imaging if not improving |
| **Uploaded PDFs** | Sample CBC Lab Result; Sample X-Ray Report; Sample Referral Letter |
| **Follow-up** | Symptoms improved; continue conservative management |
| **Final visit** | Condition resolved; advise return if new symptoms |

Walk through Sections 2–7 using these names and fictional clinical details.

---

## 9. Warning (read again before using real clinics)

| Rule | Why |
|------|-----|
| Local dev / training only | Protects real patients and complies with privacy law |
| No real patient data | Prevents accidental PHI in test databases |
| No real medical PDFs | Prevents confidential documents on unsecured laptops |
| Fake PDFs only | Keeps exercises clearly labeled as sample material |

---

## 10. Troubleshooting

### Cannot log in

- Confirm Docker containers are running and Oscar responds at your URL.
- Verify username, password, and second-level PIN with your setup documentation.
- On local OpenOSP, login issues are often fixed by database bootstrap or login-reset steps described in the main project README (not repeated here).
- Clear browser cache or try a private window if the login page loops.

### Cannot find patient

- Search by **last name** first, then first name.
- Check for extra spaces or spelling (Dela Cruz vs Delacruz).
- Confirm the demographic was **saved** (repeat Section 3.6).
- If filters exist (active only, roster, program), widen the search.

### Cannot upload PDF

- Confirm the file is a **PDF** and not corrupted.
- Check file size limits for your server.
- Ensure you are in the **patient document** area, not a provider-only folder.
- Verify the Oscar document directory on the server has write access (administrator task in Docker deployments).

### Doctor/provider does not appear

- Confirm the provider record is **active**.
- Refresh provider pick lists after creating a new doctor.
- Ensure your login has permission to select that provider.
- For appointments, confirm the provider is linked to the correct **program** or **location** if your site uses those filters.

### Encounter note not saving

- Fill **required** fields (often date, provider, or note body).
- Look for red validation messages on the form.
- Save before navigating away; some tabs lose unsaved text.
- Check whether the note must be linked to an **appointment** or **program**.

### Uploaded document not visible in chart

- Confirm upload targeted **this patient’s** demographic.
- Check document **status** (active vs archived/hidden).
- Look in both the **demographic documents** list and any **inbox/routing** view.
- Refresh the chart or log out and back in.
- Confirm the PDF file exists on the server document path (administrator check for Docker volume mounts).

---

## 11. Quick checklist

Use this list to confirm you completed the full training workflow:

- [ ] **Doctor created** (or existing provider ready): Dr. Maria Santos  
- [ ] **Patient created**: Juan Dela Cruz (fake demographics saved)  
- [ ] **First consultation saved** (cough/fever visit documented and signed if applicable)  
- [ ] **PDFs uploaded**: Sample CBC Lab Result, Sample Referral Letter, Sample X-Ray Report  
- [ ] **Follow-up consultation saved** (improving symptoms documented)  
- [ ] **Final resolved note saved** (condition resolved; chart kept as history)  

When all boxes are checked, you have completed the basic OpenOSP patient workflow from start to finish.

---

## Related project documentation

For installing and starting the application, database bootstrap, login credentials, and optional **automated sample data** in development, refer to the main **README.md** and **scripts/sample-data/README.md** in this repository. This workflow guide intentionally focuses on **how to use Oscar in the browser**, not on code or scripts.
