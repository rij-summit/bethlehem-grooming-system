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
        first_name: "Staff",
        last_name: "Bethlehem",
        username: null,
        email: "staff@placeholder.test",
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
  assert.equal(settings.staffAccounts[0].username, "");
  assert.equal(settings.staffAccounts[0].email, "staff@placeholder.test");

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

  console.log("Admin security settings regression tests passed.");
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
