import type fr from './fr'

export default {
  auth: {
    email: 'Email',
    password: 'Password',
    genericError: 'Something went wrong, please try again in a moment.',
  },
  login: {
    title: 'Log in',
    subtitle: 'Welcome back to Tower',
    submit: 'Log in',
    noAccount: 'No account yet?',
    registerLink: 'Create an account',
    invalidCredentials: 'Wrong email or password.',
    invalidEmail: 'Please check the format of your email.',
  },
  register: {
    title: 'Create an account',
    subtitle: 'Your first workspace will be created automatically',
    passwordHint: 'At least 8 characters',
    submit: 'Create my account',
    hasAccount: 'Already have an account?',
    loginLink: 'Log in',
    closed: 'Registration is closed on this instance.',
    emailTaken: 'An account already exists with this email.',
    invalid: 'Check your email and use a password of 8 to 72 characters.',
  },
  nav: {
    label: 'Main navigation',
    workspace: 'Workspace',
    manageWorkspaces: 'Manage my workspaces',
    loadingWorkspaces: 'Loading…',
    home: 'Home',
    tasks: 'Tasks',
    jira: 'Jira',
    mergeRequests: 'Merge Requests',
    history: 'History',
    settings: 'Settings',
    devices: 'Devices',
    download: 'Download',
    logout: 'Log out',
  },
} satisfies typeof fr
