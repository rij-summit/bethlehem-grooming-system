const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const root = path.resolve(__dirname, "..");
const componentSource = fs.readFileSync(
  path.join(root, "scripts/components/admin-settings.js"),
  "utf8",
);

const calls = [];
global.window = { lucide: null };
global.document = {
  getElementById() {
    return { focus() {} };
  },
};
global.API = {
  async getAdminSecurityAccounts() {
    return {
      admin: {
        user_id: 1,
        first_name: "Admin",
        last_name: "Bethlehem",
        username: "Admin",
        email: "bethlehem.admin.test@gmail.com",
        is_active: true,
        is_archived: false,
      },
      staff: [{
        user_id: 2,
        first_name: "Grooming",
        last_name: "Staff",
        username: "groomingstaff",
        email: "bethlehem.staff.test@gmail.com",
        staff_type: "grooming",
        staff_subrole: null,
        is_active: true,
        is_archived: false,
      }],
    };
  },
  async requestAdminCredentialChange(payload) {
    calls.push(["admin", payload]);
    return {
      change_id: 11,
      purpose: "Admin username change",
      target_name: "Admin Bethlehem",
      confirmation_email: "b**************@gmail.com",
    };
  },
  async requestStaffCredentialChange(staffId, payload) {
    calls.push(["staff", staffId, payload]);
    return {
      change_id: 12,
      purpose: "Staff username and password change",
      target_name: "Staff Bethlehem",
      confirmation_email: "b**************@gmail.com",
    };
  },
  async confirmSecurityCredentialChange(changeId, code) {
    calls.push(["confirm", changeId, code]);
    return {
      message: "Staff username and password change confirmed.",
      requires_reauthentication: false,
    };
  },
  async resendSecurityCredentialChangeCode(changeId) {
    calls.push(["resend", changeId]);
    return {
      message: "A new security code was sent.",
      confirmation_email: "b**************@gmail.com",
    };
  },
  async requestStaffAccount(payload) {
    calls.push(["add-staff", payload]);
    return {
      pending_staff_id: 21,
      purpose: "Verify Clinic Staff email",
      staff_label: "Clinic Staff",
      confirmation_email: "c**********@example.test",
    };
  },
  async confirmStaffAccountEmail(pendingStaffId, code) {
    calls.push(["confirm-staff", pendingStaffId, code]);
    return { message: "Clinic Staff account created." };
  },
  async resendStaffAccountEmailCode(pendingStaffId) {
    calls.push(["resend-staff", pendingStaffId]);
    return {
      message: "A new email verification code was sent.",
      confirmation_email: "c**********@example.test",
    };
  },
  async updateStaffAccountStatus(staffId, active) {
    calls.push(["staff-status", staffId, active]);
    return {
      message: active ? "Staff account reactivated." : "Staff account deactivated.",
    };
  },
  clearAuthState() {},
  redirectToSignIn() {},
};

vm.runInThisContext(componentSource, {
  filename: "scripts/components/admin-settings.js",
});

(async () => {
  const settings = adminSettings();
  settings.$nextTick = (callback) => callback();

  await settings.loadSecurityAccounts();
  assert.equal(settings.adminAccount.username, "Admin");
  assert.equal(settings.staffAccounts.length, 1);
  assert.equal(settings.staffAccounts[0].username, "groomingstaff");
  assert.equal(settings.staffAccounts[0].email, "bethlehem.staff.test@gmail.com");
  assert.equal(settings.staffAccounts[0].roleLabel, "Grooming Receptionist");

  settings.adminPassword.current = "CurrentAdmin!234";
  settings.adminPassword.username = "ClinicAdmin";
  await settings.submitAdminPassword();
  assert.equal(settings.adminPassword.error, "");
  assert.equal(settings.securityVerification.open, true);
  assert.equal(settings.securityVerification.changeId, 11);
  assert.equal(settings.securityVerification.purpose, "Admin username change");
  assert.equal(calls[0][0], "admin");
  assert.equal(calls[0][1].username, "ClinicAdmin");

  settings.closeSecurityVerification();

  const staff = settings.staffAccounts[0];
  settings.openResetStaffPassword(staff);
  settings.resetStaffModal.username = "ClinicStaff";
  settings.resetStaffModal.password = "UpdatedPass!123";
  settings.resetStaffModal.confirmation = "UpdatedPass!123";
  await settings.submitResetStaffPassword();
  assert.equal(settings.resetStaffModal.open, false);
  assert.equal(settings.securityVerification.open, true);
  assert.equal(settings.securityVerification.changeId, 12);
  assert.equal(settings.securityVerification.purpose, "Staff username and password change");
  assert.equal(calls[1][0], "staff");
  assert.equal(calls[1][1], 2);
  assert.equal(calls[1][2].username, "ClinicStaff");

  settings.securityVerification.digits = ["1", "2", "3", "4", "5", "6"];
  await settings.confirmSecurityCredentialChange();
  assert.deepEqual(calls[2], ["confirm", 12, "123456"]);
  assert.equal(settings.securityVerification.open, false);
  assert.equal(settings.staffNotice, "Staff username and password change confirmed.");

  settings.openAddStaffAccount();
  settings.chooseStaffType("clinic");
  settings.addStaffModal.staffSubrole = "veterinarian";
  settings.addStaffModal.firstName = "John";
  settings.addStaffModal.lastName = "Smith";
  settings.addStaffModal.email = "clinic.staff@example.test";
  settings.addStaffModal.password = "ClinicStaff!234";
  settings.addStaffModal.confirmation = "ClinicStaff!234";
  await settings.requestNewStaffAccount();
  assert.equal(settings.addStaffModal.step, "verify");
  assert.equal(settings.addStaffModal.purpose, "Verify Clinic Staff email");
  assert.deepEqual(calls.find((call) => call[0] === "add-staff"), [
    "add-staff",
    {
      staff_type: "clinic",
      staff_subrole: "veterinarian",
      first_name: "John",
      last_name: "Smith",
      username: null,
      email: "clinic.staff@example.test",
      password: "ClinicStaff!234",
      password_confirmation: "ClinicStaff!234",
    },
  ]);

  settings.addStaffModal.digits = ["6", "5", "4", "3", "2", "1"];
  await settings.confirmNewStaffAccount();
  assert.deepEqual(
    calls.find((call) => call[0] === "confirm-staff"),
    ["confirm-staff", 21, "654321"],
  );
  assert.equal(settings.addStaffModal.open, false);
  assert.equal(settings.staffNotice, "Clinic Staff account created.");

  settings.openStaffStatusModal(settings.staffAccounts[0]);
  await settings.updateStaffStatus();
  assert.deepEqual(
    calls.find((call) => call[0] === "staff-status"),
    ["staff-status", 2, false],
  );
  assert.equal(settings.staffStatusModal.open, false);
  assert.equal(settings.staffNotice, "Staff account deactivated.");

  console.log("Admin security settings regression tests passed.");
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
