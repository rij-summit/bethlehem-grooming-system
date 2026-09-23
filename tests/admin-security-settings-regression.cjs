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
      staff: [
        {
          user_id: 2,
          first_name: "Grooming",
          last_name: "Staff",
          username: "groomingstaff",
          email: "bethlehem.staff.test@gmail.com",
          staff_type: "grooming",
          staff_subrole: null,
          is_active: true,
          is_archived: false,
          password_setup_required: false,
        },
        {
          user_id: 3,
          first_name: "Clinic",
          last_name: "Staff",
          username: "clinicstaff",
          email: "clinic.staff.test@gmail.com",
          staff_type: "clinic",
          staff_subrole: "veterinarian",
          is_active: true,
          is_archived: false,
          password_setup_required: true,
        },
        {
          user_id: 4,
          first_name: "Former",
          last_name: "Staff",
          username: "formerstaff",
          email: "former.staff.test@gmail.com",
          staff_type: "clinic",
          staff_subrole: "clinic_receptionist",
          is_active: false,
          is_archived: false,
          password_setup_required: false,
        },
      ],
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
  async confirmSecurityCredentialChange(changeId, code) {
    calls.push(["confirm", changeId, code]);
    return {
      message: "Admin username change confirmed.",
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
      message: "Staff account created",
      username: "JohnSmith",
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
  assert.equal(settings.staffAccounts.length, 3);
  assert.equal(settings.staffAccounts[0].username, "groomingstaff");
  assert.equal(settings.staffAccounts[0].email, "bethlehem.staff.test@gmail.com");
  assert.equal(settings.staffAccounts[0].roleLabel, "Grooming Receptionist");
  assert.equal(settings.staffAccounts[0].statusLabel, "Active");
  assert.equal(settings.staffAccounts[1].statusLabel, "Setup Required");
  assert.equal(settings.staffAccounts[2].statusLabel, "Deactivated");

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
  settings.openStaffDetails(staff);
  assert.equal(settings.staffDetailsModal.open, true);
  assert.equal(settings.staffDetailsModal.staff.fullName, "Grooming Staff");
  assert.equal(settings.staffDetailsModal.staff.statusLabel, "Active");
  settings.closeStaffDetails();
  assert.equal(settings.staffDetailsModal.open, false);

  settings.openSecurityVerification({
    change_id: 11,
    purpose: "Admin username change",
    target_name: "Admin Bethlehem",
    confirmation_email: "b**************@gmail.com",
  });
  settings.securityVerification.digits = ["1", "2", "3", "4", "5", "6"];
  await settings.confirmSecurityCredentialChange();
  assert.deepEqual(calls[1], ["confirm", 11, "123456"]);
  assert.equal(settings.securityVerification.open, false);
  assert.equal(settings.staffNotice, "Admin username change confirmed.");

  settings.openAddStaffAccount();
  settings.chooseStaffType("clinic");
  assert.equal(settings.addStaffModal.step, "role");
  assert.equal(settings.addStaffModal.roleStage, "clinic");
  settings.handleAddStaffClose();
  assert.equal(settings.addStaffModal.open, true);
  assert.equal(settings.addStaffModal.roleStage, "main");
  assert.equal(settings.addStaffModal.staffType, "");
  settings.handleAddStaffClose();
  assert.equal(settings.addStaffModal.open, false);

  settings.openAddStaffAccount();
  settings.chooseStaffType("clinic");
  settings.chooseClinicSubrole("veterinarian");
  assert.equal(settings.addStaffModal.step, "details");
  assert.equal(settings.addStaffModal.staffSubrole, "veterinarian");
  settings.addStaffModal.firstName = "John";
  settings.addStaffModal.lastName = "Smith";
  settings.addStaffModal.email = "clinic.staff@example.test";
  await settings.requestNewStaffAccount();
  assert.equal(settings.addStaffModal.step, "success");
  assert.equal(settings.addStaffModal.createdUsername, "JohnSmith");
  assert.deepEqual(calls.find((call) => call[0] === "add-staff"), [
    "add-staff",
    {
      staff_type: "clinic",
      staff_subrole: "veterinarian",
      first_name: "John",
      last_name: "Smith",
      username: null,
      email: "clinic.staff@example.test",
    },
  ]);

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
